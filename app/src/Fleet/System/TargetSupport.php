<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * An applier that can tell whether the installed root helper knows a target. A host upgraded to a release that has a
 * new target keeps the old helper and sudoers line until the installer runs again.
 */
interface TargetSupport
{
    public function supports(ApplyTarget $target): bool;
}
