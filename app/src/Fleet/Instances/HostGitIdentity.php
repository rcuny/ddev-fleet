<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

/**
 * The git identity instances commit with when a project does not choose its own. FLE-51 re-points the alias in
 * services.yaml at the host page's setting.
 */
interface HostGitIdentity
{
    /**
     * @return array{name: string, email: string}|null null when the host has no default
     */
    public function default(): ?array;
}
