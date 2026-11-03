<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** One released version in CHANGELOG.md: its text (without the Rollout subsection) and its rollout notes. */
final readonly class ChangelogSection
{
    public function __construct(
        public Version $version,
        public ?string $date,
        public string $body,
        public Rollout $rollout,
    ) {
    }
}
