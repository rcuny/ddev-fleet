<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

/**
 * The default: nothing to hide yet (secrets arrive with their own masker).
 */
final class NoLogMasker implements LogMasker
{
    public function mask(string $text): string
    {
        return $text;
    }
}
