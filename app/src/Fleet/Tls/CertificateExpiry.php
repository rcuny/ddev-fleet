<?php

declare(strict_types=1);

namespace App\Fleet\Tls;

/**
 * The certificate the local Caddy serves for one name.
 */
final readonly class CertificateExpiry
{
    public function __construct(public string $name, public ?\DateTimeImmutable $expiresAt)
    {
    }
}
