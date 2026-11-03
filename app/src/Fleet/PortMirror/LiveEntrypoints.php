<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

/**
 * The seam to the running ddev-router: the names of the entrypoints it has right now.
 */
interface LiveEntrypoints
{
    /**
     * @return list<string>|null null when the router's API is unreachable or answers nonsense
     */
    public function names(): ?array;
}
