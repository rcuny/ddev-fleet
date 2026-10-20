<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

/**
 * What a sync did: whether a file changed, the public ports now mirrored (:443 included) and a reason for everything
 * that was skipped.
 */
final readonly class MirrorSync
{
    /**
     * @param list<int>    $mirrored
     * @param list<string> $skipped
     */
    public function __construct(
        public bool $changed = false,
        public array $mirrored = [],
        public array $skipped = [],
    ) {
    }
}
