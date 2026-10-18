<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HostSettingsRepositoryTest extends KernelTestCase
{
    use FleetDatabase;

    private HostSettingsRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new HostSettingsRepository($this->setUpFleetDatabase());
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testGetIsNullBeforeInit(): void
    {
        self::assertNull($this->repository->get());
    }

    public function testGetOrCreateIsASingletonRowWithId1(): void
    {
        $first = $this->repository->getOrCreate('fleet.example.com');
        $second = $this->repository->getOrCreate('other.example.com');

        self::assertSame(1, $first->id());
        self::assertSame($first, $second);
        self::assertSame('fleet.example.com', $second->domain());
        self::assertSame(TlsMode::Http01, $first->tlsMode());
        self::assertFalse($first->isSetupComplete());

        $this->entityManager()->clear();
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM host_settings'));
        self::assertSame('fleet.example.com', $this->repository->get()?->domain());
    }

    public function testTokenAndCompletionFieldsRoundTrip(): void
    {
        $settings = $this->repository->getOrCreate('fleet.example.com');
        $hash = hash('sha256', 'token');
        $expires = new \DateTimeImmutable('2026-10-19 12:00:00', new \DateTimeZone('Europe/Paris'));
        $done = new \DateTimeImmutable('2026-10-19 13:00:00 UTC');

        $settings->changeAcmeEmail('ops@example.com');
        $settings->changeTlsMode(TlsMode::OvhDns);
        $settings->storeSetupToken($hash, $expires);
        $settings->markSetupComplete($done);
        $this->repository->save($settings);
        $this->entityManager()->clear();

        $loaded = $this->repository->get();
        self::assertInstanceOf(HostSettings::class, $loaded);
        self::assertNotSame($settings, $loaded);
        self::assertSame('ops@example.com', $loaded->acmeEmail());
        self::assertSame(TlsMode::OvhDns, $loaded->tlsMode());
        self::assertSame($hash, $loaded->setupTokenHash());
        self::assertEquals($expires, $loaded->setupTokenExpiresAt());
        self::assertSame($expires->getTimestamp(), $loaded->setupTokenExpiresAt()?->getTimestamp());
        self::assertEquals($done, $loaded->setupCompletedAt());
        self::assertTrue($loaded->isSetupComplete());

        $loaded->clearSetupToken();
        $this->repository->save($loaded);
        $this->entityManager()->clear();
        self::assertNull($this->repository->get()?->setupTokenHash());
        self::assertNull($this->repository->get()?->setupTokenExpiresAt());
    }

    public function testStoreSetupTokenRejectsAnythingButASha256Digest(): void
    {
        $settings = new HostSettings('fleet.example.com');

        $this->expectException(\InvalidArgumentException::class);
        $settings->storeSetupToken('plain-token', new \DateTimeImmutable());
    }

    public function testDatesAreStoredAsUtcWhateverThePhpTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $settings = $this->repository->getOrCreate('fleet.example.com');
            $settings->markSetupComplete(new \DateTimeImmutable('2026-10-19 13:00:00', new \DateTimeZone('Asia/Tokyo')));
            $this->repository->save($settings);
            $this->entityManager()->clear();

            self::assertSame(
                '2026-10-19 04:00:00',
                $this->entityManager()->getConnection()->fetchOne('SELECT setup_completed_at FROM host_settings'),
            );
            date_default_timezone_set('America/New_York');
            self::assertSame(
                new \DateTimeImmutable('2026-10-19 04:00:00 UTC')->getTimestamp(),
                $this->repository->get()?->setupCompletedAt()?->getTimestamp(),
            );
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testReopenSetupClearsCompletionAndTheToken(): void
    {
        $settings = $this->repository->getOrCreate('fleet.example.com');
        $settings->storeSetupToken(str_repeat('a', 64), new \DateTimeImmutable('+1 day'));
        $settings->markSetupComplete();
        $this->repository->save($settings);

        $settings->reopenSetup();
        $this->repository->save($settings);
        $this->entityManager()->clear();

        $reloaded = $this->repository->get();
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isSetupComplete());
        self::assertNull($reloaded->setupTokenHash());
        self::assertNull($reloaded->setupTokenExpiresAt());
    }
}
