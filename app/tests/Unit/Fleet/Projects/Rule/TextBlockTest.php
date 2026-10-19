<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\TextBlock;
use PHPUnit\Framework\TestCase;

final class TextBlockTest extends TestCase
{
    public function testAcceptsTextUpToTheCap(): void
    {
        self::assertNull(TextBlock::error(''));
        self::assertNull(TextBlock::error("a\nb\r\n\tc"));
        self::assertNull(TextBlock::error(str_repeat('a', 10_000)));
        self::assertNull(TextBlock::error(str_repeat('é', 10_000)));
    }

    public function testRefusesWhatIsTooLongOrNotText(): void
    {
        self::assertSame(10_000, TextBlock::MAX);
        self::assertNotNull(TextBlock::error(str_repeat('a', 10_001)));
        self::assertNotNull(TextBlock::error(str_repeat('é', 10_001)));
        self::assertNotNull(TextBlock::error(str_repeat('a', 1_000_000)));
        self::assertNotNull(TextBlock::error("\xff"));
        self::assertNotNull(TextBlock::error("a\0b"));
        self::assertNotNull(TextBlock::error(null));
        self::assertNotNull(TextBlock::error(['a']));
    }

    public function testACallerCanGiveItsOwnBound(): void
    {
        self::assertNull(TextBlock::error(str_repeat('a', 50_000), 100_000));
        self::assertSame('The text must be at most 100 characters.', TextBlock::error(str_repeat('a', 101), 100));
        self::assertNotNull(TextBlock::error(str_repeat('a', 10_001)));
    }
}
