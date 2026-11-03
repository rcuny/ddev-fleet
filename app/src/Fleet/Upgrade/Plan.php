<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * What an upgrade would do: from the installed version to a target release. Pure data, built by UpgradePlanner.
 */
final readonly class Plan
{
    /**
     * @param list<Release> $releases the releases passed through, newest first: (from, to] going up, (to, from] going down
     */
    public function __construct(
        public string $from,
        public ?Version $fromVersion,
        public ?Release $target,
        public Direction $direction,
        public array $releases,
    ) {
    }

    public function isUpToDate(): bool
    {
        return Direction::Same === $this->direction;
    }

    public function hasTarget(): bool
    {
        return null !== $this->target;
    }
}
