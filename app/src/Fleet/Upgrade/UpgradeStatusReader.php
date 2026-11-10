<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** Builds the UpgradeStatus from the version file and the cached catalog. Never touches the network. */
final readonly class UpgradeStatusReader
{
    public function __construct(private InstalledVersion $installed, private ReleaseCatalog $catalog, private InstalledEngine $engine)
    {
    }

    public function read(): UpgradeStatus
    {
        $raw = $this->installed->raw();

        return new UpgradeStatus($raw, Version::tryParse($raw), $this->catalog->state(), $this->engine->name());
    }
}
