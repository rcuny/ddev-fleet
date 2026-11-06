<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;

/**
 * Threads minus the reserve (spec 2.1): the number the project and template forms quote in their help text. A light
 * read (no usage sampling), used by pages that only need the figure.
 */
final readonly class AllocatableThreads
{
    public function __construct(
        private HostHardwareProbe $hardware,
        private HostSettingsRepository $settings,
    ) {
    }

    public function get(): int
    {
        $reserve = $this->settings->get()?->cpuReserveThreads() ?? HostSettings::DEFAULT_CPU_RESERVE_THREADS;

        return max(0, $this->hardware->read()->threads - $reserve);
    }
}
