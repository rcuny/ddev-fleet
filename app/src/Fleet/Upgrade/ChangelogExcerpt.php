<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** The cached changelog sections up to the release `$upTo`, newest first. */
final readonly class ChangelogExcerpt
{
    /**
     * @param list<ChangelogSection> $sections
     */
    public function __construct(public Version $upTo, public array $sections)
    {
    }
}
