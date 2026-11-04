<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** `--to` named a version that is not a published release. */
final class UnknownRelease extends \InvalidArgumentException
{
    /**
     * @param list<Release> $available the stable releases, newest first
     */
    public function __construct(public readonly Version $requested, public readonly array $available)
    {
        parent::__construct(\sprintf(
            '%s is not a published release; available: %s',
            $requested->tag(),
            [] === $available ? 'none known (run a check first)' : implode(', ', array_map(static fn (Release $r): string => $r->tag(), $available)),
        ));
    }
}
