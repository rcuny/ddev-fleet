<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

/**
 * FLE-299: one declared link resolved against an actual source instance: which target instance, which containers, and
 * whether it can be live now. `pending` carries the reason when it cannot ("target instance missing", "target has no
 * service typesense", "source not running").
 */
final readonly class ResolvedLink
{
    public function __construct(
        public string $sourceInstance,
        public string $sourceProject,
        public string $targetInstance,
        public string $targetProject,
        public string $service,
        public int $port,
        public ?string $pending = null,
        public ?string $sourceContainer = null,
        public ?string $targetContainer = null,
        public ?string $sourceIp = null,
        public ?string $targetIp = null,
    ) {
    }

    /** The same link, not live: $why says what is missing. */
    public function pending(string $why): self
    {
        return new self($this->sourceInstance, $this->sourceProject, $this->targetInstance, $this->targetProject, $this->service, $this->port, $why, $this->sourceContainer, $this->targetContainer);
    }

    /** The same link with the addresses the two containers have on the link network (the packet-level one-way rule needs both). */
    public function withAddresses(string $sourceIp, string $targetIp): self
    {
        return new self($this->sourceInstance, $this->sourceProject, $this->targetInstance, $this->targetProject, $this->service, $this->port, null, $this->sourceContainer, $this->targetContainer, $sourceIp, $targetIp);
    }

    public function isLive(): bool
    {
        return null === $this->pending;
    }

    public function id(): string
    {
        return LinkIds::id($this->sourceInstance, $this->targetInstance, $this->service, $this->port);
    }

    public function network(): string
    {
        return LinkIds::network($this->id());
    }

    public function bridge(): string
    {
        return LinkIds::bridge($this->id());
    }

    /** The name the source resolves the target service by: `<service>.<target project>`. */
    public function alias(): string
    {
        return $this->service.'.'.$this->targetProject;
    }

    public function describe(): string
    {
        return \sprintf('%s -> %s/%s:%d', $this->sourceInstance, $this->targetInstance, $this->service, $this->port);
    }
}
