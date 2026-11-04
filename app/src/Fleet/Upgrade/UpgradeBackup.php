<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** The pre-upgrade database backup (a seam: the runner is tested with a fake). */
interface UpgradeBackup
{
    /**
     * @return string the backup file name
     *
     * @throws \RuntimeException when the backup cannot be taken; nothing partial is left behind
     */
    public function create(): string;
}
