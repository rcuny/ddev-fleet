<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * Where the Caddyfile gets the OVH API endpoint from. A seam so the renderer can be tested without a secret store.
 */
interface OvhEndpointSource
{
    /**
     * @return string|null the stored endpoint as it was saved (not yet validated), or null when none is stored
     */
    public function endpoint(): ?string;
}
