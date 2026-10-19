<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host;

use App\Fleet\Host\NotificationMailRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationMailRuleTest extends TestCase
{
    public function testValidAndEmptySettings(): void
    {
        self::assertSame([], NotificationMailRule::violations('smtp.example.com', 587, 'fleet', 'fleet@example.com', 'ops@example.com'));
        self::assertSame([], NotificationMailRule::violations(null, null, null, null, null));
        self::assertSame([], NotificationMailRule::violations(' ', null, '', ' ', ''));
        self::assertSame([], NotificationMailRule::violations('localhost', 25, null, null, null));
    }

    /**
     * @return iterable<string, array{string, string|null, int|null, string|null, string|null, string|null}>
     */
    public static function invalid(): iterable
    {
        yield 'host with path' => ['smtpHost', 'smtp.example.com/x', null, null, null, null];
        yield 'host with scheme' => ['smtpHost', 'smtps://smtp.example.com', null, null, null, null];
        yield 'host with port' => ['smtpHost', 'smtp.example.com:587', null, null, null, null];
        yield 'host with space' => ['smtpHost', 'smtp example.com', null, null, null, null];
        yield 'host with newline' => ['smtpHost', "smtp.example.com\nx", null, null, null, null];
        yield 'host leading dash' => ['smtpHost', '-smtp.example.com', null, null, null, null];
        yield 'port zero' => ['smtpPort', null, 0, null, null, null];
        yield 'port too big' => ['smtpPort', null, 65536, null, null, null];
        yield 'user with newline' => ['smtpUser', null, null, "fleet\nx", null, null];
        yield 'from not an address' => ['mailFrom', null, null, null, 'nope', null];
        yield 'to with display name' => ['mailTo', null, null, null, null, 'Ops <ops@example.com>'];
        yield 'to with newline' => ['mailTo', null, null, null, null, "ops@example.com\nBcc: x@example.com"];
    }

    #[DataProvider('invalid')]
    public function testInvalidValues(string $field, ?string $host, ?int $port, ?string $user, ?string $from, ?string $to): void
    {
        self::assertArrayHasKey($field, NotificationMailRule::violations($host, $port, $user, $from, $to));
    }

    public function testPasswords(): void
    {
        self::assertNull(NotificationMailRule::passwordViolation('pass word with spaces'));
        self::assertNull(NotificationMailRule::passwordViolation(str_repeat('p', 256)));
        self::assertNotNull(NotificationMailRule::passwordViolation(''));
        self::assertNotNull(NotificationMailRule::passwordViolation(str_repeat('p', 257)));
        self::assertNotNull(NotificationMailRule::passwordViolation("a\nb"));
        self::assertNotNull(NotificationMailRule::passwordViolation("a\0b"));
        self::assertStringNotContainsString("a\nb", (string) NotificationMailRule::passwordViolation("a\nb"));
    }
}
