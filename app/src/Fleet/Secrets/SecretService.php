<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

use App\Domain\Project\ProjectRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ValidationFailed;

/**
 * The one path to list, set, delete and reveal managed secrets, for the pages and the commands alike. It validates,
 * seals through SecretStore and records every put, delete and reveal in the audit log (name and actor, never a value).
 *
 * `$project` is a project name for a project secret and null for a fleet-wide one.
 */
final readonly class SecretService
{
    public function __construct(
        private SecretStore $store,
        private ProjectRepository $projects,
        private AuditLog $audit,
        private PlaceholderScanner $scanner,
        private RevealThrottle $throttle,
    ) {
    }

    /**
     * @throws NotFound when the project does not exist
     */
    public function overview(?string $project): SecretOverview
    {
        $this->assertProject($project);

        return new SecretOverview(
            $this->store->listManaged($project),
            null === $project ? null : $this->scanner->scan($project),
        );
    }

    /**
     * The host's own secrets (set by the setup wizard): names only, nothing can be done with them here.
     *
     * @return list<SecretSummary>
     */
    public function hostSecrets(): array
    {
        return $this->store->listHost();
    }

    /**
     * Adds a secret or replaces its value.
     *
     * @throws NotFound         when the project does not exist
     * @throws ValidationFailed with the fields `name` and `value`; the message never contains the value
     * @throws SecretConflict   when a concurrent writer created the same secret first
     */
    public function put(?string $project, mixed $name, #[\SensitiveParameter] mixed $value, string $by): void
    {
        $this->assertProject($project);
        $value = \is_string($value) ? SecretValue::normalise($value) : $value;

        $errors = [];
        if (null !== $error = SecretName::error($name)) {
            $errors['name'] = $error;
        }
        if (null !== $error = SecretValue::error($value)) {
            $errors['value'] = $error;
        }
        if ([] !== $errors || !\is_string($name) || !\is_string($value)) {
            throw new ValidationFailed($errors);
        }

        $this->store->putManaged($project, $name, $value, $by);
        $this->audit->record($by, AuditLog::SECRET_PUT, self::subject($project, $name));
    }

    /**
     * @throws NotFound when the project or the secret does not exist
     */
    public function delete(?string $project, string $name, string $by): void
    {
        $this->assertProject($project);
        if (null !== SecretName::error($name) || !$this->store->forgetManaged($project, $name)) {
            throw NotFound::secret($name);
        }
        $this->audit->record($by, AuditLog::SECRET_DELETE, self::subject($project, $name));
    }

    /**
     * Opens a value for an administrator to read once. The reveal is recorded after the value was opened and before it
     * is handed out: if the audit entry cannot be written, nothing is revealed.
     *
     * @throws NotFound         when the project or the secret does not exist
     * @throws SecretOpenFailed when the stored value cannot be authenticated
     * @throws RevealThrottled  when $by revealed too much lately; the refusal is audited and nothing is opened
     */
    public function reveal(?string $project, string $name, string $by): string
    {
        $this->assertProject($project);
        if (null !== $retryAfter = $this->throttle->attempt($by)) {
            $this->audit->record($by, AuditLog::SECRET_REVEAL_REFUSED, self::subject($project, $name));

            throw new RevealThrottled($retryAfter);
        }
        $value = null === SecretName::error($name) ? $this->store->getManaged($project, $name) : null;
        if (null === $value) {
            throw NotFound::secret($name);
        }
        $this->audit->record($by, AuditLog::SECRET_REVEAL, self::subject($project, $name));

        return $value;
    }

    /**
     * Removes every secret of a project that is being deleted, with an audit entry for each.
     */
    public function removeAllOfProject(string $project, string $by): void
    {
        foreach ($this->store->forgetAllOfProject($project) as $name) {
            $this->audit->record($by, AuditLog::SECRET_DELETE, self::subject($project, $name));
        }
    }

    public static function subject(?string $project, string $name): string
    {
        return null === $project ? 'fleet/'.$name : 'project:'.$project.'/'.$name;
    }

    private function assertProject(?string $project): void
    {
        if (null !== $project && null === $this->projects->findByName($project)) {
            throw NotFound::project($project);
        }
    }
}
