<?php

declare(strict_types=1);

namespace App\Fleet\Tls;

/**
 * Looks at the certificate Caddy presents for a name. A seam so tests need no TLS listener.
 */
interface CertificateProbe
{
    /**
     * @return CertificateExpiry|null null when no TLS listener answered at all (nothing to judge); a result with a
     *                                null expiry when Caddy answered but presented no usable certificate
     */
    public function probe(string $name): ?CertificateExpiry;
}
