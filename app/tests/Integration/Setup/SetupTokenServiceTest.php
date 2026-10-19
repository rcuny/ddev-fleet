<?php

declare(strict_types=1);

namespace App\Tests\Integration\Setup;

use App\Domain\Host\HostSettingsRepository;
use App\Setup\SetupRefused;
use App\Setup\SetupTokenService;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class SetupTokenServiceTest extends KernelTestCase
{
    use FleetDatabase;

    private HostSettingsRepository $settings;
    private MockClock $clock;
    private SetupTokenService $service;

    protected function setUp(): void
    {
        $this->settings = new HostSettingsRepository($this->setUpFleetDatabase());
        $this->clock = new MockClock('2026-10-19 10:00:00 UTC');
        $this->service = new SetupTokenService($this->settings, $this->clock);
        $this->settings->getOrCreate('fleet.example.com');
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testMintedTokenVerifies(): void
    {
        $token = $this->service->mint();

        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/D', $token), 'base64url of 32 bytes');
        self::assertTrue($this->service->verify($token));
        self::assertFalse($this->service->verify($token.'A'));
        self::assertFalse($this->service->verify(substr($token, 0, 42)));
        self::assertFalse($this->service->verify(''));
        self::assertFalse($this->service->verify(str_repeat('A', 4096)));
        self::assertFalse($this->service->verify(strrev($token)));
    }

    public function testSecondMintInvalidatesTheFirst(): void
    {
        $first = $this->service->mint();
        $second = $this->service->mint();

        self::assertNotSame($first, $second);
        self::assertFalse($this->service->verify($first));
        self::assertTrue($this->service->verify($second));
    }

    public function testExpiredTokenFails(): void
    {
        $token = $this->service->mint();

        $this->clock->modify('+23 hours 59 minutes 59 seconds');
        self::assertTrue($this->service->verify($token));

        $this->clock->modify('+1 second');
        self::assertFalse($this->service->verify($token), 'valid for 24 hours, not a second longer');
    }

    public function testTokenFailsAfterCompletion(): void
    {
        $token = $this->service->mint();
        $host = $this->settings->get();
        self::assertNotNull($host);
        $host->markSetupComplete($this->clock->now());
        $this->settings->save($host);

        self::assertFalse($this->service->verify($token), 'a token minted before completion never reopens the wizard');
        $this->expectException(SetupRefused::class);
        $this->service->mint();
    }

    public function testStoredValueIsAHashNotTheToken(): void
    {
        $token = $this->service->mint();

        $row = $this->entityManager()->getConnection()->fetchAssociative('SELECT * FROM host_settings');
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $token), $row['setup_token_hash']);
        self::assertStringNotContainsString($token, (string) json_encode($row));
    }

    public function testInvalidateForgetsTheToken(): void
    {
        $token = $this->service->mint();
        $this->service->invalidate();

        self::assertFalse($this->service->verify($token));
        self::assertNull($this->settings->get()?->setupTokenHash());
        $this->service->invalidate(); // nothing to forget: no error
    }

    public function testMintNeedsAnInitialisedHost(): void
    {
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM host_settings');
        $this->entityManager()->clear();

        $this->expectException(SetupRefused::class);
        $this->service->mint();
    }

    public function testVerifyIsFalseWithoutAHost(): void
    {
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM host_settings');
        $this->entityManager()->clear();

        self::assertFalse($this->service->verify('A'));
    }
}
