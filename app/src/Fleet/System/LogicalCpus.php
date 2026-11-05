<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * How many logical CPUs (hardware threads) the host has. The slice quotas are expressed against it, and the root helper
 * refuses a quota above `nproc --all` * 100.
 */
interface LogicalCpus
{
    /** @return int<1, max> */
    public function count(): int;
}
