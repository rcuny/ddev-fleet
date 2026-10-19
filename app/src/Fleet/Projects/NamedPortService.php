<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Fleet\Projects\Rule\PortNumbers;
use App\Fleet\Projects\Rule\ResourceName;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The one path to manage the named-port catalogue (a command line only: the catalogue has no web UI).
 */
final readonly class NamedPortService
{
    /** Project names listed in a refusal message. */
    private const int LISTED_PROJECTS = 10;

    public function __construct(private NamedPortRepository $ports)
    {
    }

    /**
     * @return list<NamedPort> ordered by name
     */
    public function list(): array
    {
        return $this->ports->all();
    }

    /**
     * @throws NotFound
     */
    public function get(string $name): NamedPort
    {
        return $this->ports->findByName($name) ?? throw NotFound::port($name);
    }

    /**
     * @throws ValidationFailed
     */
    public function create(NamedPortInput $input): NamedPort
    {
        $input = $input->normalised();
        $errors = [];

        // Port names are not reserved (unlike project and template names): "new" is not a URL here.
        if (null !== $error = ResourceName::error($input->name, false)) {
            $errors['name'] = $error;
        }
        if (null !== $error = PortNumbers::publicError($input->public)) {
            $errors['public'] = $error;
        }
        if (null !== $error = PortNumbers::routerError($input->router, $input->public)) {
            $errors['router'] = $error;
        }
        $errors += $this->collisions($input, $errors);
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        \assert(\is_int($input->public) && \is_int($input->router));
        $port = new NamedPort($input->name, $input->public, $input->router);

        try {
            $this->ports->save($port);
        } catch (UniqueConstraintViolationException $e) {
            // Two creates raced past the checks. The entity manager is closed now: touch nothing else.
            throw new ValidationFailed([self::collidingField($e->getMessage()) => 'Another port was created with this value just now.']);
        }

        return $port;
    }

    /**
     * @throws NotFound
     * @throws Refused  when a project has the port assigned
     */
    public function delete(string $name): void
    {
        $port = $this->get($name);
        $projects = $this->ports->projectNamesUsing($port);
        if ([] !== $projects) {
            $listed = \array_slice($projects, 0, self::LISTED_PROJECTS);
            $more = \count($projects) - \count($listed);

            throw new Refused(\sprintf('Used by project(s): %s%s.', implode(', ', $listed), $more > 0 ? \sprintf(' and %d more', $more) : ''));
        }

        try {
            $this->ports->remove($port);
        } catch (ForeignKeyConstraintViolationException) {
            // A project took the port between the check and the delete. The entity manager is closed now.
            throw new Refused('A project was just assigned this port.');
        }
    }

    /**
     * Name, public and router port are unique, and a public port must not equal another entry's router port (both are
     * listening ports of the host).
     *
     * @param array<string, string> $errors
     *
     * @return array<string, string> errors for the fields not in $errors yet
     */
    private function collisions(NamedPortInput $input, array $errors): array
    {
        $found = [];
        $catalogue = $this->ports->all();
        $publics = array_map(static fn (NamedPort $port): int => $port->publicPort(), $catalogue);
        $routers = array_map(static fn (NamedPort $port): int => $port->routerPort(), $catalogue);

        if (!isset($errors['name']) && null !== $this->ports->findByName($input->name)) {
            $found['name'] = 'A port with this name already exists.';
        }
        if (!isset($errors['public']) && (\in_array($input->public, $publics, true) || \in_array($input->public, $routers, true))) {
            $found['public'] = 'This port number is already used by another entry.';
        }
        if (!isset($errors['router']) && (\in_array($input->router, $routers, true) || \in_array($input->router, $publics, true))) {
            $found['router'] = 'This port number is already used by another entry.';
        }

        return $found;
    }

    private static function collidingField(string $message): string
    {
        return match (true) {
            str_contains($message, 'public_port') => 'public',
            str_contains($message, 'router_port') => 'router',
            default => 'name',
        };
    }
}
