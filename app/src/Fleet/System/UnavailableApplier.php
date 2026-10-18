<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * PLACEHOLDER, replaced in Task 6 of milestone M0.02 by the real PrivilegedApply (which runs `sudo -n apply <target>`
 * through the CommandRunner). Until then the Applier alias in config/services.yaml points here, so the container
 * compiles and any attempt to apply fails loudly instead of pretending to have installed something.
 *
 * Task 6: delete this class and repoint the `App\Fleet\System\Applier` alias to PrivilegedApply.
 */
final class UnavailableApplier implements Applier
{
    public function apply(ApplyTarget $target): void
    {
        throw new \RuntimeException(\sprintf('Applying "%s" is not available yet: the privileged helper arrives in a later task.', $target->value));
    }
}
