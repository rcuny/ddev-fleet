<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\BranchName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BranchNameTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        foreach (['main', 'develop', 'feature/FLE-37-projects', 'release/1.2.0', 'v1.0', 'a', str_repeat('a', 200)] as $branch) {
            yield substr($branch, 0, 30) => [$branch];
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejected(): iterable
    {
        foreach (['-x', 'a..b', 'a//b', 'a/', 'a.', 'a.lock', 'a/b.lock', 'a.lock/b', 'a b', 'a@{1}', '.hidden', 'a/.b', '', str_repeat('a', 201), "main\n", "ma\0in", 'HEAD', 'refs/heads/x', 'a~1', 'a^', 'a:b', 'a\\b', 'a?b', 'a*b', 'a[b', 'é', 5, null] as $i => $branch) {
            yield '#'.$i.' '.(\is_string($branch) ? addcslashes(substr($branch, 0, 20), "\0..\37") : get_debug_type($branch)) => [$branch];
        }
    }

    #[DataProvider('accepted')]
    public function testAccepts(string $branch): void
    {
        self::assertNull(BranchName::error($branch));
    }

    #[DataProvider('rejected')]
    public function testRejects(mixed $branch): void
    {
        $error = BranchName::error($branch);

        self::assertIsString($error);
        if (\is_string($branch) && \strlen($branch) >= 4) {
            self::assertStringNotContainsString($branch, $error);
        }
    }
}
