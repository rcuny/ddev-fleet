<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\CommandLines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandLinesTest extends TestCase
{
    public function testNormaliseTrimsAndDropsBlankLines(): void
    {
        self::assertSame(['a', 'b'], CommandLines::normalise("a\n\n  b  \r\n"));
        self::assertSame(['a', 'b', 'c'], CommandLines::normalise("a\rb\r\nc"));
        self::assertSame([], CommandLines::normalise(" \n\t\n"));
        self::assertSame([], CommandLines::normalise(''));
        self::assertSame(['a b'], CommandLines::normalise(' a b '));
    }

    public function testNormaliseKeepsWhatValidationMustSee(): void
    {
        self::assertSame(["a\0"], CommandLines::normalise("a\0"));
        self::assertSame(["\e[31m"], CommandLines::normalise("\e[31m"));
        self::assertSame(['a'], CommandLines::normalise("a\n"));
    }

    public function testValidLines(): void
    {
        self::assertNull(CommandLines::error([]));
        self::assertNull(CommandLines::error(['ddev composer install']));
        self::assertNull(CommandLines::error(['drush cr', 'echo "é — ü"']));
        self::assertNull(CommandLines::error([str_repeat('x', 2000)]));
        self::assertNull(CommandLines::error(array_fill(0, 50, 'ls')));
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejected(): iterable
    {
        $cases = [
            'newline' => ["a\nb"],
            'carriage return' => ["a\rb"],
            'nul' => ["a\x00"],
            'escape' => ["\e[31m"],
            'tab' => ["a\tb"],
            'del' => ["a\x7f"],
            'c1 control' => ["a\u{85}b"],
            'line separator' => ["a\u{2028}b"],
            'paragraph separator' => ["a\u{2029}b"],
            'bidi override' => ["a\u{202E}b"],
            'too long' => [str_repeat('x', 2001)],
            'very long' => [str_repeat('x', 100_000)],
            'too many lines' => array_fill(0, 51, 'ls'),
            'empty' => [''],
            'blank' => [' '],
            'invalid utf-8' => ["\xff"],
            'truncated utf-8' => ["a\xc3"],
            'a string' => 'string',
            'null' => null,
            'not a list' => ['a' => 'ls'],
            'non-string line' => [5],
            'nested' => [['ls']],
        ];
        foreach ($cases as $label => $lines) {
            yield $label => [$lines];
        }
    }

    #[DataProvider('rejected')]
    public function testRejects(mixed $lines): void
    {
        self::assertIsString(CommandLines::error($lines));
    }

    public function testTheMessageNamesTheOneBasedLine(): void
    {
        self::assertStringContainsString('line 2', (string) CommandLines::error(['ok', "bad\x00"]));
        self::assertStringContainsString('line 1', (string) CommandLines::error(['']));
        self::assertStringContainsString('line 3', (string) CommandLines::error(['a', 'b', str_repeat('x', 2001)]));
    }

    public function testTheMessageDoesNotEchoTheLine(): void
    {
        self::assertStringNotContainsString('secret-token', (string) CommandLines::error(["secret-token\x00"]));
    }
}
