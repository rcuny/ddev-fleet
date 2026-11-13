<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Domain\Port\NamedPort;

/**
 * What the router should mirror: the catalogue ports that passed the checks (by public port), the router https port the
 * :443 site mirror passes through to (null: no site mirror) and a reason for every port or the site mirror that was
 * skipped.
 */
final readonly class MirrorPlan
{
    public const string ENTRYPOINT_PREFIX = 'fleet-mirror-';
    public const int SITE_PUBLIC_PORT = 443;
    public const string SITE_ENTRYPOINT = self::ENTRYPOINT_PREFIX.self::SITE_PUBLIC_PORT;

    /**
     * @param list<NamedPort> $ports
     * @param list<string>    $skipped
     */
    public function __construct(
        public array $ports = [],
        public ?int $siteTarget = null,
        public array $skipped = [],
        /** The :443 passthrough sends a PROXY header: only while the router's LIVE static configuration trusts it (FLE-326), else the mirror works without it. */
        public bool $proxy = false,
    ) {
    }

    /**
     * DDEV's name for the router's https entry point (`http-<port>`, also for TLS), where the :443 site mirror re-enters the router.
     * FLE-326: that entry point trusts a PROXY header from the loopback so the client's address survives the passthrough.
     */
    public static function siteTargetEntrypoint(int $httpsPort): string
    {
        return 'http-'.$httpsPort;
    }

    public static function entrypoint(int $public): string
    {
        return self::ENTRYPOINT_PREFIX.$public;
    }

    /**
     * @return list<int> the public ports, :443 included when the site mirror is active, sorted
     */
    public function publicPorts(): array
    {
        $ports = array_map(static fn (NamedPort $port): int => $port->publicPort(), $this->ports);
        if (null !== $this->siteTarget) {
            $ports[] = self::SITE_PUBLIC_PORT;
        }
        sort($ports);

        return $ports;
    }

    /**
     * @return list<string> the entrypoint names fleet wants live, sorted
     */
    public function wantedNames(): array
    {
        $names = array_map(self::entrypoint(...), $this->publicPorts());
        sort($names);

        return $names;
    }

    public function isEmpty(): bool
    {
        return [] === $this->ports && null === $this->siteTarget;
    }

    public function withProxy(bool $proxy): self
    {
        return new self($this->ports, $this->siteTarget, $this->skipped, $proxy);
    }

    public function withSkipped(string $reason): self
    {
        return new self($this->ports, $this->siteTarget, [...$this->skipped, $reason], $this->proxy);
    }
}
