<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Upgrade\Release;

/** The migrations a release ships. */
interface TargetMigrations
{
    /**
     * @return list<string>|null short class names; null when they cannot be read (no history for that commit)
     */
    public function at(Release $release): ?array;
}
