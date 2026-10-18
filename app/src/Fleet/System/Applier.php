<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * Installs the config staged for a target through the privileged helper. Callers depend on this interface so they
 * never learn how the privilege is obtained.
 */
interface Applier
{
    /**
     * @throws \RuntimeException when the helper refuses or fails; the message is safe to show
     */
    public function apply(ApplyTarget $target): void;
}
