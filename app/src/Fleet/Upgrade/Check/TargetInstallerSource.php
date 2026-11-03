<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Upgrade\Release;

/** Gives the pre-flight a copy of the target release's installer to run, and takes it away again. */
interface TargetInstallerSource
{
    /**
     * @return string|null the directory holding the release's tree (its `install/` inside); null when it cannot be
     *                     fetched or does not match the release's commit
     */
    public function fetch(Release $release): ?string;

    public function discard(string $dir): void;
}
