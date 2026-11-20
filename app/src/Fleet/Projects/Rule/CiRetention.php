<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * The CI retention of a project (FLE-367): whole days from 1, null meaning the retention is off.
 */
final class CiRetention
{
    public const int MAX = 3650;

    private function __construct()
    {
    }

    public static function error(?int $days): ?string
    {
        if (null === $days) {
            return null;
        }
        if ($days < 1) {
            return 'Enter a whole number of days from 1, or leave it empty to turn the retention off.';
        }

        return $days > self::MAX ? \sprintf('At most %d days.', self::MAX) : null;
    }
}
