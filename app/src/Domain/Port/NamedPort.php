<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry of the named-port catalogue: a service port a project can expose publicly (Caddy listens on the public
 * port and forwards to the DDEV router port). Public and router ports are unique across the catalogue.
 */
#[ORM\Entity]
#[ORM\Table(name: 'named_port')]
#[ORM\UniqueConstraint(name: 'uniq_named_port_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'uniq_named_port_public', columns: ['public_port'])]
#[ORM\UniqueConstraint(name: 'uniq_named_port_router', columns: ['router_port'])]
class NamedPort
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $name;

    #[ORM\Column(type: Types::INTEGER)]
    private int $publicPort;

    #[ORM\Column(type: Types::INTEGER)]
    private int $routerPort;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, int $publicPort, int $routerPort, ?\DateTimeImmutable $now = null)
    {
        $this->name = $name;
        $this->publicPort = $publicPort;
        $this->routerPort = $routerPort;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function publicPort(): int
    {
        return $this->publicPort;
    }

    public function routerPort(): int
    {
        return $this->routerPort;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
