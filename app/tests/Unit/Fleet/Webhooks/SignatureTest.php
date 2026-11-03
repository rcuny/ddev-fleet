<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Webhooks;

use App\Fleet\Webhooks\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    public function testAGoodSignatureIsAccepted(): void
    {
        $header = Signature::sign('{"a":1}', 'topsecret');

        self::assertMatchesRegularExpression('/^sha256=[0-9a-f]{64}$/', $header);
        self::assertTrue(Signature::valid('{"a":1}', $header, 'topsecret'));
        self::assertTrue(Signature::valid('{"a":1}', strtoupper($header) === $header ? $header : 'sha256='.strtoupper(substr($header, 7)), 'topsecret'), 'hex case does not matter');
    }

    public function testAWrongSecretOrBodyIsRefused(): void
    {
        $header = Signature::sign('{"a":1}', 'topsecret');

        self::assertFalse(Signature::valid('{"a":1}', $header, 'another'));
        self::assertFalse(Signature::valid('{"a":2}', $header, 'topsecret'));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function malformed(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'no prefix' => [str_repeat('a', 64)];
        yield 'wrong algorithm' => ['sha1='.str_repeat('a', 40)];
        yield 'short' => ['sha256=abc'];
        yield 'not hex' => ['sha256='.str_repeat('z', 64)];
        yield 'trailing newline' => [Signature::sign('x', 's')."\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testAMalformedHeaderIsRefused(?string $header): void
    {
        self::assertFalse(Signature::valid('x', $header, 's'));
    }
}
