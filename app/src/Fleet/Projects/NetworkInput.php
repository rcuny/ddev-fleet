<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

/**
 * What the Network tab submits: the hostnames, the Typesense flag, the assigned catalogue ports and, per port name, the
 * access the page asks for ("gated" or "public"). Only the ports the project already has may change their access.
 */
final readonly class NetworkInput
{
    /**
     * @param list<string>          $hostnames
     * @param list<string>          $ports
     * @param array<string, string> $access    port name => requested access
     */
    public function __construct(
        public array $hostnames,
        public bool $typesense,
        public array $ports,
        public array $access = [],
        public bool $typesenseDashboard = false,
    ) {
    }
}
