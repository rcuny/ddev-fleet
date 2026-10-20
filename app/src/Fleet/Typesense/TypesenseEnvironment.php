<?php

declare(strict_types=1);

namespace App\Fleet\Typesense;

use App\Domain\Instance\Instance;
use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\Project;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Jobs\SecretRegistry;

/**
 * What an instance of a Typesense-enabled project gets in its `web_environment`, as in 0.x: the public host and port of
 * its own Typesense (https, implicit), the admin key for backend indexing and the search-only key. Nothing for a
 * project without the flag. Every key is registered with the job log masker.
 */
final readonly class TypesenseEnvironment
{
    public const string PORT_NAME = 'typesense';

    /** The public port the catalogue seeds (FLE-92), used when an operator removed the entry. */
    private const int DEFAULT_PUBLIC_PORT = 9108;

    public function __construct(
        private TypesenseKeys $keys,
        private NamedPortRepository $ports,
        private InstanceHostnames $hostnames,
        private SecretRegistry $registry,
    ) {
    }

    /**
     * @return list<string> `NAME=value` entries, empty when the project has no Typesense
     */
    public function variables(Instance $instance, Project $project): array
    {
        if (!$project->typesense()) {
            return [];
        }

        $keys = $this->keys->ensure($project);
        $this->registry->register($keys->admin);
        $this->registry->register($keys->search);

        return [
            'FLEET_TYPESENSE_HOST='.$this->hostnames->primary($instance->id()),
            'FLEET_TYPESENSE_PORT='.$this->publicPort(),
            TypesenseKeys::ADMIN.'='.$keys->admin,
            TypesenseKeys::SEARCH.'='.$keys->search,
        ];
    }

    public function routerPort(): int
    {
        return $this->port()?->routerPort() ?? 8108;
    }

    private function publicPort(): int
    {
        return $this->port()?->publicPort() ?? self::DEFAULT_PUBLIC_PORT;
    }

    private function port(): ?NamedPort
    {
        return $this->ports->findByName(self::PORT_NAME);
    }
}
