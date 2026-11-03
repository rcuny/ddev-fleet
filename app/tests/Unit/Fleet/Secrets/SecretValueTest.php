<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Secrets;

use App\Fleet\Secrets\SecretStore;
use App\Fleet\Secrets\SecretValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretValueTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalised(): iterable
    {
        yield 'unchanged' => ['abc', 'abc'];
        yield 'one trailing newline' => ["abc\n", 'abc'];
        yield 'several trailing newlines' => ["abc\n\n\r\n", 'abc'];
        yield 'crlf inside' => ["a\r\nb", "a\nb"];
        yield 'lone cr inside stays' => ["a\rb", "a\rb"];
        yield 'inner and outer spaces stay' => ['  abc  ', '  abc  '];
        yield 'only newlines' => ["\r\n\r\n", ''];
        yield 'leading newline stays' => ["\nabc", "\nabc"];
    }

    #[DataProvider('normalised')]
    public function testNormalise(string $input, string $expected): void
    {
        self::assertSame($expected, SecretValue::normalise($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'plain' => ['abc'];
        yield 'one byte' => ['x'];
        yield 'multi-line' => ["-----BEGIN KEY-----\nabc\n-----END KEY-----"];
        yield 'utf-8' => ['pässwörd-日本'];
        yield 'quote inside' => ['a"b'];
        yield 'one leading quote only' => ['"abc'];
        yield 'one trailing quote only' => ['abc"'];
        yield 'two different quotes' => ['"abc\''];
        yield 'a single quote character' => ['"'];
        yield 'largest' => [str_repeat('x', SecretStore::MAX_VALUE_BYTES)];
    }

    #[DataProvider('valid')]
    public function testValid(string $value): void
    {
        self::assertNull(SecretValue::error($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => ['', 'Enter a value'];
        yield 'null' => [null, 'Enter a value'];
        yield 'array' => [['x'], 'Enter a value'];
        yield 'too long' => [str_repeat('x', SecretStore::MAX_VALUE_BYTES + 1), 'at most 64 KiB'];
        yield 'nul byte' => ["a\0b", 'must be text'];
        yield 'invalid utf-8' => ["a\xFFb", 'must be text'];
        yield 'double quoted' => ['"abc"', 'surrounding quotes'];
        yield 'single quoted' => ["'abc'", 'surrounding quotes'];
        yield 'empty quotes' => ['""', 'surrounding quotes'];
    }

    #[DataProvider('invalid')]
    public function testInvalid(mixed $value, string $message): void
    {
        self::assertStringContainsString($message, (string) SecretValue::error($value));
    }

    public function testTheMessageNeverContainsTheValue(): void
    {
        $value = '"super-secret-value"';

        self::assertStringNotContainsString('super-secret-value', (string) SecretValue::error($value));
    }
}
