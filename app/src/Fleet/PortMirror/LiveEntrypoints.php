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

    /**
     * Whether an entry point accepts a PROXY protocol header from some address (FLE-326: the https entry point of the :443 mirror).
     *
     * @return bool|null null when the API is unreachable or does not list the entry point
     */
    public function proxyTrusted(string $name): ?bool;
}
