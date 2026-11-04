<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * One look at the release source: refreshes the release catalog, and when the host is behind also the cached
 * changelog of what it would install. The only place the Upgrade page's data is fetched (daily timer, "Check now").
 */
final readonly class UpgradeRefresher
{
    public function __construct(
        private ReleaseCatalog $catalog,
        private ChangelogCache $changelog,
        private InstalledVersion $installed,
    ) {
    }

    public function refresh(): CatalogState
    {
        $state = $this->catalog->refresh();
        $latest = $state->latest();
        $installed = $this->installed->version();
        if (!$state->hasFailed() && null !== $latest && null !== $installed && $latest->version->isNewerThan($installed)) {
            $this->changelog->refresh($installed, $latest->version);
        }

        return $state;
    }
}
