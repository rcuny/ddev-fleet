<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * Whether this host can put instances under a CPU slice (capacity spec section 8): the installed root helper knows the
 * `slices` target. On a host that has not been upgraded yet, an instance must deploy exactly as before: a
 * `cgroup_parent` naming a slice nobody created would not fail, systemd would create it with no limit (FLE-198).
 * An applier that cannot tell (not a TargetSupport) counts as available, as in SlicesApplier.
 */
final readonly class SlicesAvailability
{
    public function __construct(private Applier $applier)
    {
    }

    public function isAvailable(): bool
    {
        return !$this->applier instanceof TargetSupport || $this->applier->supports(ApplyTarget::Slices);
    }
}
