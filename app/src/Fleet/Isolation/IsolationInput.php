<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Host\IsolationMode;

/**
 * FLE-299: everything the ruleset depends on. `observedFlows` ("src dst port") and `observedHost` ("src port") carry the
 * flows recorded so far so that a re-apply (which replaces the whole table) does not lose them.
 */
final readonly class IsolationInput
{
    /**
     * @param list<string>                              $routerIps     ddev-router's addresses on ddev_default
     * @param list<int>                                 $publicPorts   the named-port catalogue's public ports
     * @param list<int>                                 $extraPorts    isolation_host_ports
     * @param list<ResolvedLink>                        $links         the live links (their networks exist)
     * @param list<array{0: string, 1: string, 2: int}> $observedFlows [src ip, dst ip, port]
     * @param list<array{0: string, 1: int}>            $observedHost  [src ip, port]
     */
    public function __construct(
        public IsolationMode $mode,
        public string $bridge,
        public array $routerIps = [],
        public array $publicPorts = [],
        public array $extraPorts = [],
        public array $links = [],
        public array $observedFlows = [],
        public array $observedHost = [],
    ) {
    }

    public function withoutObserved(): self
    {
        return new self($this->mode, $this->bridge, $this->routerIps, $this->publicPorts, $this->extraPorts, $this->links, [], []);
    }
}
