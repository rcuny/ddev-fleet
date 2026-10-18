<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TrustedProxyChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrustedProxyCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function peers(): iterable
    {
        yield 'loopback v4' => ['127.0.0.1', true];
        yield 'loopback v6' => ['::1', true];
        yield 'inside the CIDR' => ['10.1.2.3', true];
        yield 'outside' => ['203.0.113.9', false];
        yield 'null' => [null, false];
        yield 'empty' => ['', false];
        yield 'garbage' => ['not-an-ip', false];
        yield 'cidr text is not an address' => ['10.0.0.0/8', false];
    }

    #[DataProvider('peers')]
    public function testTrust(?string $ip, bool $expected): void
    {
        self::assertSame($expected, new TrustedProxyChecker(' 127.0.0.1, ::1 ,10.0.0.0/8,')->isTrusted($ip));
    }

    public function testEmptyListTrustsNothing(): void
    {
        $checker = new TrustedProxyChecker('');

        self::assertFalse($checker->isTrusted('127.0.0.1'));
        self::assertFalse($checker->isTrusted('::1'));
    }
}
