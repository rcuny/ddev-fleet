<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\Instances\CommandLineSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandLineSplitterTest extends TestCase
{
    public function testSplitsPlainAndQuotedWords(): void
    {
        self::assertSame(['ddev', 'init'], CommandLineSplitter::split('ddev init'));
        self::assertSame(['ddev', 'drush', 'cr'], CommandLineSplitter::split("  ddev \t drush   cr \n"));
        self::assertSame(['a b', 'c d', 'e'], CommandLineSplitter::split("'a b' \"c d\" e"));
        self::assertSame(['ab', 'c'], CommandLineSplitter::split("a'b' c"));
        self::assertSame(['', 'x'], CommandLineSplitter::split("'' x"));
        self::assertSame(['ddev', 'exec', 'drush cr | cat'], CommandLineSplitter::split("ddev exec 'drush cr | cat'"));
    }

    public function testBackslashEscapes(): void
    {
        self::assertSame(['a b'], CommandLineSplitter::split('a\\ b'));
        self::assertSame(['say "hi"'], CommandLineSplitter::split('"say \\"hi\\""'));
        self::assertSame(['back\\slash'], CommandLineSplitter::split('"back\\\\slash"'));
        self::assertSame(['a\\nb'], CommandLineSplitter::split('"a\\nb"'), 'other escapes keep the backslash inside double quotes');
        self::assertSame(['a\\nb'], CommandLineSplitter::split("'a\\nb'"), 'single quotes are literal');
        self::assertSame(['$HOME'], CommandLineSplitter::split('\\$HOME'));
    }

    public function testQuotedTokensStayOneWord(): void
    {
        self::assertSame(['ddev', 'drush', 'user:create', '[[issue]]-user'], CommandLineSplitter::split('ddev drush user:create [[issue]]-user'));
    }

    public function testUnbalancedQuoteIsRefused(): void
    {
        foreach (["echo 'oops", 'echo "oops', 'echo a\\'] as $line) {
            try {
                CommandLineSplitter::split($line);
                self::fail('expected InvalidArgumentException for: '.$line);
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEmptyLineIsRefused(): void
    {
        foreach (['', '   ', "\t\n"] as $line) {
            try {
                CommandLineSplitter::split($line);
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('empty', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellSyntax(): iterable
    {
        yield 'pipe' => ['a | b'];
        yield 'and' => ['a && b'];
        yield 'semicolon' => ['a; b'];
        yield 'redirect out' => ['a > f'];
        yield 'redirect in' => ['a < f'];
        yield 'substitution' => ['a $(x)'];
        yield 'variable' => ['a $HOME'];
        yield 'backticks' => ['a `x`'];
        yield 'background' => ['a &'];
        yield 'braces' => ['a {x,y}'];
        yield 'subshell' => ['(a)'];
        yield 'glued' => ['a;b'];
    }

    #[DataProvider('shellSyntax')]
    public function testRefusesShellSyntax(string $line): void
    {
        try {
            CommandLineSplitter::split($line);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ddev exec', $e->getMessage());
            self::assertStringNotContainsString($line, $e->getMessage(), 'the line is not echoed');
        }
    }

    public function testQuotedMetacharactersAreLiteral(): void
    {
        self::assertSame(['echo', 'a|b', '$(x)', '`y`', '{z}', 'a;b', 'a&&b'], CommandLineSplitter::split("echo 'a|b' '\$(x)' '`y`' '{z}' \"a;b\" 'a&&b'"));
        self::assertSame(['a $x'], CommandLineSplitter::split('"a $x"'));
    }
}
