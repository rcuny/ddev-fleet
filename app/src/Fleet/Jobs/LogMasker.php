<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

/**
 * Hides secret values in job output before it is written to the log file.
 */
interface LogMasker
{
    public function mask(string $text): string;
}
