<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

/**
 * Sizes for people: binary units, one decimal from KiB up ("512 B", "1.5 KiB", "2.0 GiB").
 */
final class ByteSize
{
    private const array UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];

    private function __construct()
    {
    }

    public static function format(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024.0 && $unit < \count(self::UNITS) - 1) {
            $value /= 1024.0;
            ++$unit;
        }

        return 0 === $unit ? \sprintf('%d B', $bytes) : \sprintf('%.1f %s', $value, self::UNITS[$unit]);
    }
}
