<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host;

use App\Fleet\Host\GitIdentityRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitIdentityRuleTest extends TestCase
{
    public function testAValidIdentityHasNoViolations(): void
    {
        self::assertSame([], GitIdentityRule::violations('Fleet Bot', 'bot@example.com'));
        self::assertSame([], GitIdentityRule::violations('  Zoë Müller-Łukasiewicz ', ' zoe@example.com '));
        self::assertSame([], GitIdentityRule::violations(str_repeat('é', 100), 'a@example.com'));
    }

    public function testNeitherIsValidAndBlankCountsAsAbsent(): void
    {
        self::assertSame([], GitIdentityRule::violations(null, null));
        self::assertSame([], GitIdentityRule::violations('', '  '));
        self::assertSame(['name' => null, 'email' => null], GitIdentityRule::normalise(' ', ''));
        self::assertSame(['name' => 'A', 'email' => 'a@example.com'], GitIdentityRule::normalise(' A ', ' a@example.com '));
    }

    /**
     * @return iterable<string, array{string|null, string|null, 'name'|'email'}>
     */
    public static function invalid(): iterable
    {
        yield 'name only' => ['Fleet Bot', null, 'email'];
        yield 'email only' => [null, 'bot@example.com', 'name'];
        yield 'newline in name' => ["Fleet\nBot", 'bot@example.com', 'name'];
        yield 'carriage return in name' => ["Fleet\rBot", 'bot@example.com', 'name'];
        yield 'tab in name' => ["Fleet\tBot", 'bot@example.com', 'name'];
        yield 'nul in name' => ["Fleet\0Bot", 'bot@example.com', 'name'];
        yield 'delete in name' => ["Fleet\x7FBot", 'bot@example.com', 'name'];
        yield 'angle bracket open' => ['Fleet <Bot', 'bot@example.com', 'name'];
        yield 'angle bracket close' => ['Fleet Bot>', 'bot@example.com', 'name'];
        yield 'name too long' => [str_repeat('n', 101), 'bot@example.com', 'name'];
        yield 'invalid utf-8 name' => ["Fleet \xC3\x28", 'bot@example.com', 'name'];
        yield 'no at sign' => ['Fleet Bot', 'bot.example.com', 'email'];
        yield 'space in email' => ['Fleet Bot', 'bot @example.com', 'email'];
        yield 'newline in email' => ['Fleet Bot', "bot@example.com\nBcc: x@example.com", 'email'];
        yield 'angle brackets in email' => ['Fleet Bot', 'Bot <bot@example.com>', 'email'];
        yield 'email too long' => ['Fleet Bot', str_repeat('a', 250).'@example.com', 'email'];
    }

    /**
     * @param 'name'|'email' $field
     */
    #[DataProvider('invalid')]
    public function testInvalidIdentitiesNameTheOffendingField(?string $name, ?string $email, string $field): void
    {
        $violations = GitIdentityRule::violations($name, $email);

        self::assertArrayHasKey($field, $violations);
        self::assertNotSame('', $violations[$field]);
    }
}
