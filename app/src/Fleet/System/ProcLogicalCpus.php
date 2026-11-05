<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * Counts the `processor` entries of /proc/cpuinfo (what `nproc --all` counts, without starting a process).
 */
final readonly class ProcLogicalCpus implements LogicalCpus
{
    public function __construct(private string $cpuinfo = '/proc/cpuinfo')
    {
    }

    public function count(): int
    {
        $text = @file_get_contents($this->cpuinfo);
        $count = false === $text ? 0 : preg_match_all('/^processor\s*:/m', $text);

        return max(1, (int) $count);
    }
}
