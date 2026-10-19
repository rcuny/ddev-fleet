<?php

declare(strict_types=1);

namespace App\Fleet\Host;

/**
 * Whether the operating system asked for a reboot, and which packages asked for it.
 */
final readonly class RebootStatus
{
    /**
     * @param list<string> $packages  sanitised package names, at most RebootSignal::MAX_PACKAGES
     * @param bool         $truncated more packages were listed than are shown
     */
    public function __construct(
        public bool $required,
        public array $packages = [],
        public bool $truncated = false,
    ) {
    }
}
