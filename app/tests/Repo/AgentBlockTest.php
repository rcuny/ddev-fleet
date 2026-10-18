<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

final class AgentBlockTest extends TestCase
{
    public function testClaudeMdHoldsTheManagedBlockExactlyOnce(): void
    {
        $file = \dirname(__DIR__, 3).'/CLAUDE.md';
        self::assertFileExists($file);
        $content = (string) file_get_contents($file);

        self::assertSame(1, substr_count($content, '<!-- symfony-design-system:start -->'));
        self::assertSame(1, substr_count($content, '<!-- symfony-design-system:end -->'));
        self::assertLessThan(
            strpos($content, '<!-- symfony-design-system:end -->'),
            strpos($content, '<!-- symfony-design-system:start -->'),
        );
    }
}
