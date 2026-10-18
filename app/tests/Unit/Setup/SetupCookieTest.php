<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use App\Domain\Host\HostSettings;
use App\Setup\SetupCookie;
use App\Setup\SetupRefused;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Cookie;

final class SetupCookieTest extends TestCase
{
    private const string SECRET = 'a-long-enough-app-secret-for-tests';

    private MockClock $clock;
    private SetupCookie $cookie;
    private HostSettings $host;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-19 10:00:00 UTC');
        $this->cookie = new SetupCookie(self::SECRET, $this->clock);
        $this->host = new HostSettings('fleet.example.com');
        $this->mint('a');
    }

    private function mint(string $char): void
    {
        $this->host->storeSetupToken(str_repeat($char, 64), $this->clock->now()->modify('+24 hours'));
    }

    public function testRoundTrip(): void
    {
        $issued = $this->cookie->issue($this->host);

        self::assertTrue($this->cookie->isValid($issued->getValue(), $this->host));
    }

    public function testTamperedValueFails(): void
    {
        $value = (string) $this->cookie->issue($this->host)->getValue();
        [$expiry, $mac] = explode('.', $value);

        foreach ([
            $expiry.'.'.strrev($mac),
            ((int) $expiry + 1).'.'.$mac,
            $expiry.'.'.substr($mac, 0, 63),
            $expiry,
            '',
            $value.'0',
            '.'.$mac,
            'x.'.$mac,
        ] as $tampered) {
            self::assertFalse($this->cookie->isValid($tampered, $this->host), $tampered);
        }
        self::assertFalse($this->cookie->isValid(null, $this->host));
    }

    public function testAnotherAppSecretDoesNotValidate(): void
    {
        $value = $this->cookie->issue($this->host)->getValue();

        self::assertFalse(new SetupCookie('another-long-enough-app-secret!!', $this->clock)->isValid($value, $this->host));
    }

    public function testInvalidAfterReMint(): void
    {
        $value = $this->cookie->issue($this->host)->getValue();
        $this->mint('b');

        self::assertFalse($this->cookie->isValid($value, $this->host));
    }

    public function testInvalidAfterCompletion(): void
    {
        $value = $this->cookie->issue($this->host)->getValue();
        $this->host->markSetupComplete($this->clock->now());
        self::assertFalse($this->cookie->isValid($value, $this->host));

        $this->host->clearSetupToken();
        self::assertFalse($this->cookie->isValid($value, $this->host));
        $this->expectException(SetupRefused::class);
        $this->cookie->issue($this->host);
    }

    public function testInvalidAfterTheTokenWasConsumed(): void
    {
        $value = $this->cookie->issue($this->host)->getValue();
        $this->host->clearSetupToken();

        self::assertFalse($this->cookie->isValid($value, $this->host));
    }

    public function testExpires(): void
    {
        $value = $this->cookie->issue($this->host)->getValue();

        $this->clock->modify('+1 hour 59 minutes 59 seconds');
        self::assertTrue($this->cookie->isValid($value, $this->host));
        $this->clock->modify('+1 second');
        self::assertFalse($this->cookie->isValid($value, $this->host));
    }

    public function testNeverOutlivesTheToken(): void
    {
        $this->host->storeSetupToken(str_repeat('c', 64), $this->clock->now()->modify('+10 minutes'));
        $issued = $this->cookie->issue($this->host);

        self::assertSame($this->clock->now()->getTimestamp() + 600, $issued->getExpiresTime());
    }

    public function testCookieFlags(): void
    {
        $issued = $this->cookie->issue($this->host);

        self::assertSame('__Host-fleet_setup', $issued->getName());
        self::assertSame('/', $issued->getPath());
        self::assertNull($issued->getDomain(), '__Host- forbids a Domain attribute');
        self::assertTrue($issued->isSecure());
        self::assertTrue($issued->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $issued->getSameSite());
        self::assertSame($this->clock->now()->getTimestamp() + 7200, $issued->getExpiresTime());
        self::assertStringContainsString('samesite=strict', (string) $issued);
        self::assertStringNotContainsString(str_repeat('a', 64), (string) $issued, 'the token digest is not in the cookie');
    }

    public function testAnUnusableAppSecretFailsClosed(): void
    {
        $weak = new SetupCookie('short', $this->clock);

        self::assertFalse($weak->isValid($this->cookie->issue($this->host)->getValue(), $this->host));
        $this->expectException(SetupRefused::class);
        $weak->issue($this->host);
    }
}
