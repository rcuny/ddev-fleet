<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Fleet\Secrets\SecretStore;

/**
 * The endpoint the setup wizard (or `fleet host:ovh-credentials`) stored under the host secret `ovh_endpoint`.
 */
final readonly class StoredOvhEndpoint implements OvhEndpointSource
{
    public function __construct(private SecretStore $secrets)
    {
    }

    public function endpoint(): ?string
    {
        return $this->secrets->getFleetWide('ovh_endpoint');
    }
}
