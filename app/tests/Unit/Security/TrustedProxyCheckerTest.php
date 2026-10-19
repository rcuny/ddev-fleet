<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TrustedProxyChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyCheckerTest extends TestCase
{
    private const string SECRET = 'a-proxy-secret-of-at-least-32-characters-long';

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
        self::assertSame($expected, new TrustedProxyChecker(' 127.0.0.1, ::1 ,10.0.0.0/8,', '')->isTrusted($ip));
    }

    public function testEmptyListTrustsNothing(): void
    {
        $checker = new TrustedProxyChecker('', '');

        self::assertFalse($checker->isTrusted('127.0.0.1'));
        self::assertFalse($checker->isTrusted('::1'));
    }

    /**
     * @param array<string, string> $server
     */
    private static function request(string $peer, array $server = []): Request
    {
        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $peer] + $server);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function mappedPeers(): iterable
    {
        yield 'dotted' => ['::ffff:127.0.0.1', '127.0.0.1', true];
        yield 'hex' => ['::ffff:7f00:1', '127.0.0.1', true];
        yield 'upper case, expanded' => ['0:0:0:0:0:FFFF:7F00:0001', '127.0.0.1', true];
        yield 'mapped inside a CIDR' => ['::ffff:10.1.2.3', '10.0.0.0/8', true];
        yield 'mapped but not listed' => ['::ffff:203.0.113.9', '127.0.0.1', false];
        yield 'plain v6 is not v4' => ['::2', '127.0.0.1', false];
        yield 'compat address is not mapped' => ['::7f00:1', '127.0.0.1', false];
        yield 'ffff in the wrong group' => ['::ffff:0:1', '::1', false];
    }

    #[DataProvider('mappedPeers')]
    public function testIpv4MappedIpv6PeerMatchesIpv4Entry(string $peer, string $entry, bool $expected): void
    {
        $checker = new TrustedProxyChecker($entry, '');

        self::assertSame($expected, $checker->isTrusted($peer));
        self::assertSame($expected, $checker->isTrustedRequest(self::request($peer)));
    }

    public function testProxySecretParamTrustsRequestFromAnyPeer(): void
    {
        $checker = new TrustedProxyChecker('', self::SECRET);
        $request = self::request('203.0.113.9', [TrustedProxyChecker::PROXY_PARAM => self::SECRET]);

        self::assertFalse($checker->isTrusted('203.0.113.9'));
        self::assertTrue($checker->isTrustedRequest($request));
    }

    public function testTheParamNameIsFixed(): void
    {
        self::assertSame('FLEET_PROXY_AUTH', TrustedProxyChecker::PROXY_PARAM);
    }

    public function testWrongSecretIsNotTrusted(): void
    {
        $checker = new TrustedProxyChecker('', self::SECRET);

        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9', [TrustedProxyChecker::PROXY_PARAM => self::SECRET.'x'])));
        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9', [TrustedProxyChecker::PROXY_PARAM => ''])));
        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9')));
    }

    public function testAClientHeaderCannotStandInForTheServerParam(): void
    {
        $checker = new TrustedProxyChecker('', self::SECRET);

        // A client-sent "FLEET_PROXY_AUTH" or "Fleet-Proxy-Auth" header becomes HTTP_FLEET_PROXY_AUTH, never the param.
        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9', ['HTTP_FLEET_PROXY_AUTH' => self::SECRET])));
        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9', ['HTTP_FLEET_PROXY_AUTH' => self::SECRET, 'HTTP_X_FLEET_PROXY_AUTH' => self::SECRET])));
    }

    public function testEmptyConfiguredSecretNeverTrustsEvenWithEmptyParam(): void
    {
        $checker = new TrustedProxyChecker('', '');

        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9', [TrustedProxyChecker::PROXY_PARAM => ''])));
        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9')));
    }

    public function testSecretShorterThan32IsIgnored(): void
    {
        $short = str_repeat('s', 31);
        $checker = new TrustedProxyChecker('', $short);

        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9', [TrustedProxyChecker::PROXY_PARAM => $short])));

        $exact = str_repeat('s', 32);
        self::assertTrue(new TrustedProxyChecker('', $exact)->isTrustedRequest(self::request('203.0.113.9', [TrustedProxyChecker::PROXY_PARAM => $exact])));
    }

    public function testListedPeerStillTrustedWithoutSecret(): void
    {
        $checker = new TrustedProxyChecker('127.0.0.1', self::SECRET);

        self::assertTrue($checker->isTrustedRequest(self::request('127.0.0.1')));
        self::assertTrue($checker->isTrustedRequest(self::request('::ffff:127.0.0.1')));
        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9')));
    }

    public function testForwardedForCannotMakeARequestTrusted(): void
    {
        $checker = new TrustedProxyChecker('127.0.0.1', self::SECRET);

        self::assertFalse($checker->isTrustedRequest(self::request('203.0.113.9', ['HTTP_X_FORWARDED_FOR' => '127.0.0.1'])));
    }

    public function testMissingRemoteAddrIsNotTrusted(): void
    {
        $checker = new TrustedProxyChecker('127.0.0.1', self::SECRET);

        self::assertFalse($checker->isTrustedRequest(new Request()));
    }
}
