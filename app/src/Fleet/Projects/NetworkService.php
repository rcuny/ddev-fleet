<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

use App\Domain\Port\NamedPort;
use App\Domain\Project\Project;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Instances\InstanceSiteRefresher;

/**
 * The Network tab: hostnames, Typesense, port assignment and port access in one save. The rules stay in ProjectService
 * and NamedPortService (the same ones `fleet project:update` and `fleet port:update` use); this class adds the
 * comparison that decides what needs a confirmation, and the audit entries of the changes that widen access.
 */
final readonly class NetworkService
{
    public function __construct(
        private ProjectService $projects,
        private NamedPortService $ports,
        private AuditLog $audit,
        private InstanceSiteRefresher $sites,
    ) {
    }

    /**
     * What the submission would change, hostnames first, then the assignment, then the access. Nothing is written.
     *
     * @return list<NetworkChange>
     */
    public function plan(Project $project, NetworkInput $input): array
    {
        $changes = [];

        $oldHosts = $project->additionalHostnames();
        $newHosts = ProjectInput::fromProject($project)->with(['additionalHostnames' => $input->hostnames])->normalised()->additionalHostnames;
        foreach (array_diff($newHosts, $oldHosts) as $host) {
            $changes[] = new NetworkChange(NetworkChange::HOSTNAME_ADD, $host, true);
        }
        foreach (array_diff($oldHosts, $newHosts) as $host) {
            $changes[] = new NetworkChange(NetworkChange::HOSTNAME_REMOVE, $host, false);
        }

        $catalogue = [];
        foreach ($this->ports->list() as $port) {
            $catalogue[$port->name()] = $port;
        }
        $assigned = array_map(static fn (NamedPort $port): string => $port->name(), $project->ports());
        $wanted = ProjectInput::fromProject($project)->with(['ports' => $input->ports])->normalised()->ports;
        foreach (array_diff($wanted, $assigned) as $name) {
            $changes[] = new NetworkChange(NetworkChange::PORT_ASSIGN, $name, NamedPort::PUBLIC === ($catalogue[$name] ?? null)?->access());
        }
        foreach (array_diff($assigned, $wanted) as $name) {
            $changes[] = new NetworkChange(NetworkChange::PORT_UNASSIGN, $name, NamedPort::PUBLIC === ($catalogue[$name] ?? null)?->access());
        }

        foreach ($input->access as $name => $access) {
            $port = $catalogue[$name] ?? null;
            if (null !== $port && \in_array($name, $assigned, true) && \in_array($name, $wanted, true) && strtolower(trim($access)) !== $port->access()) {
                $changes[] = new NetworkChange(NetworkChange::PORT_ACCESS, $name, true, $port->access(), strtolower(trim($access)));
            }
        }

        return $changes;
    }

    /**
     * Throws what apply() would refuse the submission with, before anything is confirmed or written.
     *
     * @throws ValidationFailed
     */
    public function validate(Project $project, NetworkInput $input): void
    {
        $errors = $this->projects->validate(ProjectInput::fromProject($project)->with([
            'additionalHostnames' => $input->hostnames,
            'typesense' => $input->typesense,
            'ports' => $input->ports,
        ])->normalised(), $project);
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }
    }

    /**
     * Whether any change in the list needs the user's confirmation.
     *
     * @param list<NetworkChange> $changes
     */
    public static function needsConfirmation(array $changes): bool
    {
        foreach ($changes as $change) {
            if ($change->risky) {
                return true;
            }
        }

        return false;
    }

    /**
     * Saves the submission. Everything is checked before the first write: an unknown or unassigned port, or an access
     * other than "gated" or "public", refuses the whole save.
     *
     * @throws NotFound
     * @throws ValidationFailed
     */
    public function apply(string $name, NetworkInput $input, string $actor): Project
    {
        $project = $this->projects->get($name);
        $changes = $this->plan($project, $input);

        $accessChanges = array_values(array_filter($changes, static fn (NetworkChange $change): bool => NetworkChange::PORT_ACCESS === $change->kind));
        $assigned = array_map(static fn (NamedPort $port): string => $port->name(), $project->ports());
        foreach ($input->access as $port => $access) {
            if (!\in_array(strtolower(trim($access)), [NamedPort::GATED, NamedPort::PUBLIC], true)) {
                throw new ValidationFailed(['access' => 'Access must be "gated" or "public".']);
            }
            if (!\in_array($port, $assigned, true)) {
                throw new ValidationFailed(['access' => \sprintf('Assign the port "%s" to this project and save before changing its access.', $port)]);
            }
        }

        $updated = $this->projects->update($name, ProjectInput::fromProject($project)->with([
            'additionalHostnames' => $input->hostnames,
            'typesense' => $input->typesense,
            'ports' => $input->ports,
        ]));

        foreach ($accessChanges as $change) {
            $this->ports->setAccess($change->subject, (string) $change->to);
        }
        if ([] !== $accessChanges) {
            // The save is done; a site Caddy refuses is a warning (fleet:port:refresh retries), as on the command line.
            $this->sites->refresh();
        }

        foreach ($changes as $change) {
            if ($change->risky || NetworkChange::HOSTNAME_REMOVE === $change->kind || NetworkChange::PORT_UNASSIGN === $change->kind) {
                $this->audit->record($actor, $change->auditAction(), $change->auditSubject($name));
            }
        }

        return $updated;
    }
}
