<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Tests\Support\FleetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testTheUploadCapDefaultsToTwoGiBAndRoundTrips(): void
    {
        $settings = $this->repository->getOrCreate('fleet.example.com');
        self::assertSame(2_147_483_648, $settings->assetUploadMaxBytes());

        $settings->changeAssetUploadMaxBytes(5_000_000_000);
        $this->repository->save($settings);
        $this->entityManager()->clear();

        self::assertSame(5_000_000_000, $this->repository->get()?->assetUploadMaxBytes(), 'above 32 bits survives SQLite');
        self::assertSame(5_000_000_000, (int) $this->entityManager()->getConnection()->fetchOne('SELECT asset_upload_max_bytes FROM host_settings'));

        $this->expectException(\InvalidArgumentException::class);
        $settings->changeAssetUploadMaxBytes(0);
    }

    public function testTheCapColumnHasADatabaseDefaultSoExistingRowsGetOne(): void
    {
        $columns = $this->entityManager()->getConnection()->fetchAllAssociative('PRAGMA table_info(host_settings)');
        $cap = array_values(array_filter($columns, static fn (array $column): bool => 'asset_upload_max_bytes' === $column['name']));

        self::assertCount(1, $cap);
        self::assertSame('1', (string) $cap[0]['notnull']);
        self::assertSame('2147483648', (string) $cap[0]['dflt_value']);
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

    public function testNewHostSettingsFieldsDefaultAndRoundTrip(): void
    {
        $settings = $this->repository->getOrCreate('fleet.example.com');
        self::assertSame(14, $settings->backupRetentionDays());
        self::assertNull($settings->gitUserName());
        self::assertNull($settings->gitUserEmail());
        self::assertNull($settings->smtpHost());
        self::assertNull($settings->smtpPort());
        self::assertNull($settings->smtpUser());
        self::assertNull($settings->mailFrom());
        self::assertNull($settings->mailTo());

        $settings->changeBackupRetentionDays(30);
        $settings->changeGitIdentity('Fleet Bot', 'bot@example.com');
        $settings->changeNotificationMail('smtp.example.com', 587, 'mailer', 'fleet@example.com', '  ops@example.com ');
        $this->repository->save($settings);
        $this->entityManager()->clear();

        $loaded = $this->repository->get();
        self::assertNotNull($loaded);
        self::assertSame(30, $loaded->backupRetentionDays());
        self::assertSame('Fleet Bot', $loaded->gitUserName());
        self::assertSame('bot@example.com', $loaded->gitUserEmail());
        self::assertSame('smtp.example.com', $loaded->smtpHost());
        self::assertSame(587, $loaded->smtpPort());
        self::assertSame('mailer', $loaded->smtpUser());
        self::assertSame('fleet@example.com', $loaded->mailFrom());
        self::assertSame('ops@example.com', $loaded->mailTo());

        $loaded->changeGitIdentity('', null);
        $loaded->changeNotificationMail(null, null, '', null, null);
        $this->repository->save($loaded);
        $this->entityManager()->clear();

        $cleared = $this->repository->get();
        self::assertNull($cleared?->gitUserName());
        self::assertNull($cleared?->gitUserEmail());
        self::assertNull($cleared?->smtpHost());
        self::assertNull($cleared?->smtpPort());
        self::assertNull($cleared?->smtpUser());
        self::assertSame(30, $cleared?->backupRetentionDays(), 'untouched');
    }

    public function testRowsCreatedBeforeTheMigrationGetTheDefaultRetention(): void
    {
        $this->repository->getOrCreate('fleet.example.com');
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('UPDATE host_settings SET backup_retention_days = 14');
        $this->entityManager()->clear();

        self::assertSame(14, $this->repository->get()?->backupRetentionDays());
        // the column default (rows inserted without the column, e.g. by an older release) is 14 as well
        $connection->executeStatement('DELETE FROM host_settings');
        $connection->executeStatement("INSERT INTO host_settings (id, domain, tls_mode, created_at, updated_at) VALUES (1, 'fleet.example.com', 'http01', '2026-10-19 00:00:00', '2026-10-19 00:00:00')");
        self::assertSame(14, (int) $connection->fetchOne('SELECT backup_retention_days FROM host_settings'));
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRefused(\Closure $change): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $change(new HostSettings('fleet.example.com'));
    }

    /**
     * @return iterable<string, array{\Closure(HostSettings): void}>
     */
    public static function invalidValues(): iterable
    {
        yield 'retention 0' => [static fn (HostSettings $s) => $s->changeBackupRetentionDays(0)];
        yield 'retention too long' => [static fn (HostSettings $s) => $s->changeBackupRetentionDays(HostSettings::MAX_BACKUP_RETENTION_DAYS + 1)];
        yield 'port 0' => [static fn (HostSettings $s) => $s->changeNotificationMail('smtp.example.com', 0, null, null, null)];
        yield 'port too high' => [static fn (HostSettings $s) => $s->changeNotificationMail('smtp.example.com', 65536, null, null, null)];
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
