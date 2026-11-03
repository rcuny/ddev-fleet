<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\DrupalEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DrupalEnvTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        foreach (['dev', 'staging-2', 'a.b_c', str_repeat('a', 64)] as $value) {
            yield substr($value, 0, 30) => [$value];
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejected(): iterable
    {
        foreach (['', 'a b', 'a=b', '$x', 'a"b', 'a;b', 'a`b', 'a#b', "dev\n", "de\0v", 'dév', str_repeat('a', 65), null, 5] as $i => $value) {
            yield '#'.$i.' '.(\is_string($value) ? addcslashes(substr($value, 0, 20), "\0..\37\177..\377") : get_debug_type($value)) => [$value];
        }
    }

    #[DataProvider('accepted')]
    public function testAccepts(string $value): void
    {
        self::assertNull(DrupalEnv::error($value));
    }

    #[DataProvider('rejected')]
    public function testRejects(mixed $value): void
    {
        self::assertIsString(DrupalEnv::error($value));
    }
}
