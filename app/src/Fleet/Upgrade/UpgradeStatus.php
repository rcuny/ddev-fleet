<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** What the host runs against what has been published, as last seen (no network). */
final readonly class UpgradeStatus
{
    public function __construct(
        /** The raw installed version: `v1.2.0`, `main@abc1234` or `dev`. */
        public string $installed,
        public ?Version $installedVersion,
        public CatalogState $catalog,
    ) {
    }

    public function latest(): ?Release
    {
        return $this->catalog->latest();
    }

    /** True when the newest stable release is newer than the installed one. A branch install is never "behind". */
    public function isBehind(): bool
    {
        $latest = $this->latest();

        return null !== $latest && null !== $this->installedVersion && $latest->version->isNewerThan($this->installedVersion);
    }
}
