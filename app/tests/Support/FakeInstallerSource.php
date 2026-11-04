<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fleet\Upgrade\Check\TargetInstallerSource;
use App\Fleet\Upgrade\Release;

/** A target installer source that hands out a prepared directory and counts the discards. */
final class FakeInstallerSource implements TargetInstallerSource
{
    public int $discarded = 0;

    public function __construct(private readonly ?string $dir)
    {
    }

    public function fetch(Release $release): ?string
    {
        return $this->dir;
    }

    public function discard(string $dir): void
    {
        ++$this->discarded;
    }
}
