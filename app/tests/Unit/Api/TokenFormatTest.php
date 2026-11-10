<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Token\TokenFormat;
use PHPUnit\Framework\TestCase;

final class TokenFormatTest extends TestCase
{
    public function testAGeneratedTokenHasTheDocumentedShape(): void
    {
        $token = TokenFormat::generate();

        self::assertMatchesRegularExpression('/^flt_svc_[0-9A-Za-z]{49}$/D', $token);
        self::assertTrue(TokenFormat::isWellFormed($token));
        self::assertSame(8, \strlen(TokenFormat::hint($token)));
        self::assertSame(substr($token, 8, 8), TokenFormat::hint($token));
        self::assertSame(64, \strlen(TokenFormat::hash($token)));
        self::assertNotSame($token, TokenFormat::hash($token));
    }

    public function testTokensAreUnique(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $seen[TokenFormat::generate()] = true;
        }

        self::assertCount(200, $seen);
    }

    public function testEverySingleCharacterChangeBreaksTheChecksum(): void
    {
        $token = TokenFormat::generate();
        $misses = 0;
        for ($i = 8; $i < \strlen($token); ++$i) {
            $mangled = $token;
            $mangled[$i] = '0' === $token[$i] ? '1' : '0';
            $misses += TokenFormat::isWellFormed($mangled) ? 1 : 0;
        }

        // CRC32 detects every single-character substitution.
        self::assertSame(0, $misses);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'prefix only' => ['flt_svc_'];
        yield 'other prefix' => ['ghp_'.str_repeat('a', 49)];
        yield 'newline suffix' => [TokenFormat::generate()."\n"];
        yield 'symbol inside' => ['flt_svc_'.str_repeat('a', 20).'-'.str_repeat('a', 28)];
        yield 'unicode digits' => ['flt_svc_'.str_repeat('٣', 49)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testMalformedTokensAreRejected(string $token): void
    {
        self::assertFalse(TokenFormat::isWellFormed($token));
    }
}
