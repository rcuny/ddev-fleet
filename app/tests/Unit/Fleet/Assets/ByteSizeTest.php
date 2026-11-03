<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Assets;

use App\Fleet\Assets\ByteSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ByteSizeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function sizes(): iterable
    {
        yield 'zero' => [0, '0 B'];
        yield 'bytes' => [1023, '1023 B'];
        yield 'one KiB' => [1024, '1.0 KiB'];
        yield 'KiB and a half' => [1536, '1.5 KiB'];
        yield 'MiB' => [8 * 1024 * 1024, '8.0 MiB'];
        yield 'the 2 GiB default cap' => [2_147_483_648, '2.0 GiB'];
        yield 'TiB' => [1024 ** 4, '1.0 TiB'];
        yield 'negative is zero' => [-5, '0 B'];
    }

    #[DataProvider('sizes')]
    public function testFormat(int $bytes, string $expected): void
    {
        self::assertSame($expected, ByteSize::format($bytes));
    }
}
