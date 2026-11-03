<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

/**
 * The default identity: `ddev-fleet bot <bot@DOMAIN>`.
 */
final readonly class DomainGitIdentity implements HostGitIdentity
{
    public const string NAME = 'ddev-fleet bot';

    public function __construct(private InstanceHostnames $hostnames)
    {
    }

    /**
     * @return array{name: string, email: string}
     */
    public function default(): array
    {
        return ['name' => self::NAME, 'email' => 'bot@'.$this->hostnames->domain()];
    }
}
