<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\SubmodulePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubmodulePathTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        foreach (['web/themes/custom', 'sub', 'a.b/c_d', 'a-b', str_repeat('a', 200)] as $path) {
            yield substr($path, 0, 30) => [$path];
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejected(): iterable
    {
        foreach (['/abs', 'a/../b', '..', '.', 'a//b', 'a/', 'a\\b', './a', 'a/./b', 'a/.git', '.git', '-x', 'a/-x', '', str_repeat('a', 201), "sub\n", "su\0b", 'a b', 'é', null, 5] as $i => $path) {
            yield '#'.$i.' '.(\is_string($path) ? addcslashes(substr($path, 0, 20), "\0..\37") : get_debug_type($path)) => [$path];
        }
    }

    #[DataProvider('accepted')]
    public function testAccepts(string $path): void
    {
        self::assertNull(SubmodulePath::error($path));
    }

    #[DataProvider('rejected')]
    public function testRejects(mixed $path): void
    {
        self::assertIsString(SubmodulePath::error($path));
    }
}
