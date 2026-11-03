<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fleet\System\OvhEndpointSource;

final readonly class FixedOvhEndpoint implements OvhEndpointSource
{
    public function __construct(private ?string $endpoint = 'ovh-eu')
    {
    }

    public function endpoint(): ?string
    {
        return $this->endpoint;
    }
}
