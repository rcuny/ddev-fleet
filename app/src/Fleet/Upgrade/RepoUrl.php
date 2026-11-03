<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * Where releases come from: the public GitHub mirror, or FLEET_UPGRADE_REPO_URL (tests, mirrors, rehearsals).
 */
final readonly class RepoUrl
{
    public const string DEFAULT = 'https://github.com/rcuny/ddev-fleet.git';

    public string $value;

    public function __construct(string $override = '')
    {
        $this->value = '' === trim($override) ? self::DEFAULT : trim($override);
    }

    /** `owner/repo` when the source is a github.com repository, else null. */
    public function githubSlug(): ?string
    {
        if (1 === preg_match('~^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?/?$~D', $this->value, $m)) {
            return $m[1];
        }

        return null;
    }

    /** A repository on this machine (a path or file://): its changelog is read with git, not over HTTP. */
    public function localPath(): ?string
    {
        if (str_starts_with($this->value, 'file://')) {
            return substr($this->value, 7);
        }

        return str_starts_with($this->value, '/') ? $this->value : null;
    }
}
