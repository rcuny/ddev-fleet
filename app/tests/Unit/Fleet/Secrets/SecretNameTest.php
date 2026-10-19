<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Secrets;

use App\Fleet\Secrets\SecretName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'single letter' => ['A'];
        yield 'with underscores and digits' => ['JIRA_CLAUDE_TOKEN_2'];
        yield 'longest' => [str_repeat('A', 64)];
        yield 'trailing underscore' => ['TOKEN_'];
    }

    #[DataProvider('valid')]
    public function testValidNames(string $name): void
    {
        self::assertNull(SecretName::error($name));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['A']];
        yield 'int' => [5];
        yield 'lowercase' => ['token'];
        yield 'mixed case' => ['Token'];
        yield 'leading digit' => ['1TOKEN'];
        yield 'leading underscore' => ['_TOKEN'];
        yield 'dash' => ['MY-TOKEN'];
        yield 'space' => ['MY TOKEN'];
        yield 'dot' => ['MY.TOKEN'];
        yield 'newline at the end' => ["TOKEN\n"];
        yield 'too long' => [str_repeat('A', 65)];
        yield 'non ascii' => ['TÖKEN'];
        yield 'nul' => ["TO\0KEN"];
    }

    #[DataProvider('invalid')]
    public function testInvalidNames(mixed $name): void
    {
        self::assertNotNull(SecretName::error($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function builtIns(): iterable
    {
        foreach (SecretName::BUILT_IN_TOKENS as $token) {
            yield $token => [strtoupper(str_replace('-', '_', $token))];
        }
    }

    #[DataProvider('builtIns')]
    public function testASecretMayNotShadowABuiltInToken(string $name): void
    {
        self::assertStringContainsString('built-in', (string) SecretName::error($name));
    }

    public function testTokenIsTheKebabCaseOfTheName(): void
    {
        self::assertSame('jira-claude-token', SecretName::token('JIRA_CLAUDE_TOKEN'));
        self::assertSame('a', SecretName::token('A'));
        self::assertSame('token-', SecretName::token('TOKEN_'));
    }

    public function testFromTokenIsTheInverse(): void
    {
        self::assertSame('JIRA_CLAUDE_TOKEN', SecretName::fromToken('jira-claude-token'));
        self::assertSame('A1_B', SecretName::fromToken('a1-b'));
        self::assertNull(SecretName::fromToken('1abc'), 'a name cannot start with a digit');
        self::assertNull(SecretName::fromToken('-abc'));
        self::assertNull(SecretName::fromToken(''));
        self::assertNull(SecretName::fromToken(str_repeat('a', 65)));
    }

    public function testEveryValidNameRoundTripsThroughItsToken(): void
    {
        foreach (['A', 'A_B', 'A__B', 'TOKEN_', 'X1_Y2', str_repeat('Z', 64)] as $name) {
            self::assertSame($name, SecretName::fromToken(SecretName::token($name)), $name);
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', SecretName::token($name));
        }
    }
}
