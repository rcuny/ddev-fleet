<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Fleet\FleetPaths;
use App\Fleet\Projects\Rule\SshKeyNames;

/**
 * Whether the host file behind an extra key name (a template's `ssh_keys`, FLE-190) may be loaded into an instance's
 * agent: a regular file (never a symlink), mode 0600, owned by the user fleet runs as. The name is re-checked against
 * the allow-list so no caller can reach another path through this class.
 */
final readonly class ExtraSshKey
{
    public function __construct(private FleetPaths $paths)
    {
    }

    /**
     * @return string|null why the key cannot be used, without any path
     */
    public function problem(string $name): ?string
    {
        if (null !== SshKeyNames::error([$name])) {
            return \sprintf('"%s" is not a valid SSH key name.', $name);
        }
        $file = $this->paths->sshExtraKey($name);
        if (is_link($file) || !is_file($file)) {
            return \sprintf('The SSH key "%s" does not exist on this host (expected ssh/%s_ed25519 in the fleet home).', $name, $name);
        }
        $stat = @stat($file);
        if (false === $stat) {
            return \sprintf('The SSH key "%s" cannot be read.', $name);
        }
        if (0o600 !== ($stat['mode'] & 0o777)) {
            return \sprintf('The SSH key "%s" must have mode 0600.', $name);
        }
        if (\function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
            return \sprintf('The SSH key "%s" must be owned by the user the fleet runs as.', $name);
        }

        return null;
    }

    public function path(string $name): string
    {
        return $this->paths->sshExtraKey($name);
    }
}
