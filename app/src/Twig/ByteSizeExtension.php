<?php

declare(strict_types=1);

namespace App\Twig;

use App\Fleet\Assets\ByteSize;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ file.size|bytes }}` -> "1.5 MiB".
 */
final class ByteSizeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('bytes', static fn (int|float|string|null $bytes): string => ByteSize::format((int) $bytes))];
    }
}
