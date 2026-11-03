<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Backup;

use App\Fleet\Backup\BackupKind;
use App\Fleet\Backup\BackupNotFound;
use App\Fleet\Backup\BackupService;
use App\Fleet\Backup\BackupTooLarge;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretKeyFingerprint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BackupServiceTest extends TestCase
{
    private string $home;
    private FleetPaths $paths;
    private MockClock $clock;
    private BackupService $service;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fleet-backup-'.bin2hex(random_bytes(6));
        $this->paths = new FleetPaths($this->home, $this->home.'/caddy');
        $this->paths->ensureLayout();
        $this->clock = new MockClock('2026-10-19 08:30:15 UTC');
        $this->service = new BackupService($this->paths, $this->clock, new SecretKeyFingerprint(new SecretKeyFile($this->paths)));
        $this->seed($this->paths->fleetDb(), 'host', 'fleet.example.com');
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->home);
    }

    public function testCreateWritesAReadableConsistentCopyWithMode0600(): void
    {
        $backup = $this->service->create();

        self::assertSame('fleet-20261019-083015.sqlite', $backup->name);
        self::assertSame(BackupKind::Fleet, $backup->kind);
        $path = $this->service->path($backup->name);
        clearstatcache();
        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame($backup->size, filesize($path));
        self::assertSame('fleet.example.com', $this->value($path, 'host'));
        self::assertSame(['fleet-20261019-083015.sqlite'], $this->names(), 'no temp file is left behind, no queue db yet');
    }

    public function testCreateWithinRequestLimitRefusesLargeDatabasesAndWritesNothing(): void
    {
        $size = (int) filesize($this->paths->fleetDb());

        try {
            $this->service->createWithinRequestLimit($size - 1);
            self::fail('A database above the limit must be refused.');
        } catch (BackupTooLarge $e) {
            self::assertSame($size, $e->size);
            self::assertSame($size - 1, $e->limit);
        }
        self::assertSame([], $this->names());

        $backup = $this->service->createWithinRequestLimit($size);
        self::assertSame(['fleet-20261019-083015.sqlite'], $this->names());
        self::assertSame('fleet-20261019-083015.sqlite', $backup->name);
    }

    public function testTheRequestLimitCountsTheWalAndShmFiles(): void
    {
        $size = (int) filesize($this->paths->fleetDb());
        file_put_contents($this->paths->fleetDb().'-wal', str_repeat('w', 1000));
        file_put_contents($this->paths->fleetDb().'-shm', str_repeat('s', 500));

        try {
            $this->service->createWithinRequestLimit($size + 1499);
            self::fail('The -wal and -shm files must count towards the limit.');
        } catch (BackupTooLarge $e) {
            self::assertSame($size + 1500, $e->size);
        }
        self::assertSame([], $this->names());
    }

    public function testCopyIsASnapshotOfCommittedDataEvenInWalMode(): void
    {
        $live = new \PDO('sqlite:'.$this->paths->fleetDb(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $live->exec('PRAGMA journal_mode=WAL');
        $live->exec("INSERT INTO kv VALUES ('later', 'committed in the wal')");

        // an open uncommitted writer must not leak into, or block, the copy
        $live->beginTransaction();
        $live->exec("INSERT INTO kv VALUES ('pending', 'uncommitted')");

        $backup = $this->service->create();
        $live->rollBack();

        self::assertSame('committed in the wal', $this->value($this->service->path($backup->name), 'later'));
        self::assertNull($this->value($this->service->path($backup->name), 'pending'));
    }

    public function testCreateAlsoCopiesTheQueueDatabaseWhenItExists(): void
    {
        $this->seed($this->paths->queueDb(), 'q', 'one');

        $this->service->create();

        self::assertSame(['fleet-20261019-083015.sqlite', 'queue-20261019-083015.sqlite'], $this->names());
        self::assertSame('one', $this->value($this->service->path('queue-20261019-083015.sqlite'), 'q'));
        self::assertCount(1, $this->service->list(BackupKind::Queue));
        self::assertCount(1, $this->service->list(BackupKind::Fleet));
        clearstatcache();
        self::assertSame(0o600, fileperms($this->paths->backupsDir().'/queue-20261019-083015.sqlite') & 0o777);
    }

    public function testCreateFailsCleanlyWhenTheDatabaseIsMissingOrNotSqlite(): void
    {
        unlink($this->paths->fleetDb());
        try {
            $this->service->create();
            self::fail('a missing database must fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not exist', $e->getMessage());
        }

        file_put_contents($this->paths->fleetDb(), str_repeat('not a database ', 100));
        try {
            $this->service->create();
            self::fail('a corrupt database must fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Cannot back up', $e->getMessage());
        }
        self::assertSame([], $this->names(), 'nothing partial is left');
        self::assertSame([], glob($this->paths->backupsDir().'/.tmp-*'));
    }

    public function testListIsNewestFirstAndOnlyStrictNames(): void
    {
        $dir = $this->paths->backupsDir();
        foreach (['fleet-20261001-010101.sqlite' => 100, 'fleet-20261003-010101.sqlite' => 300, 'fleet-20261002-010101.sqlite' => 200] as $name => $mtime) {
            touch($dir.'/'.$name, $mtime);
        }
        foreach (['notes.txt', 'fleet-2026.sqlite', 'fleet-20261001-010101.sqlite.bak', '.tmp-abc', 'fleet-20261001-010101.sqlite~', 'other-20261001-010101.sqlite'] as $stray) {
            touch($dir.'/'.$stray, 400);
        }
        mkdir($dir.'/fleet-20261009-010101.sqlite');
        symlink($this->paths->fleetDb(), $dir.'/fleet-20261010-010101.sqlite');

        $list = $this->service->list();

        self::assertSame(['fleet-20261003-010101.sqlite', 'fleet-20261002-010101.sqlite', 'fleet-20261001-010101.sqlite'], array_map(static fn ($i) => $i->name, $list));
        self::assertSame(300, $list[0]->modifiedAt->getTimestamp());
        self::assertSame(0, $list[0]->size);
    }

    public function testPruneDeletesOldBackupsButKeepsTheNewestOfEachKind(): void
    {
        $dir = $this->paths->backupsDir();
        $now = $this->clock->now()->getTimestamp();
        $day = 86400;
        foreach ([
            'fleet-20260901-000000.sqlite' => 40 * $day,
            'fleet-20260920-000000.sqlite' => 15 * $day,
            'fleet-20261010-000000.sqlite' => 9 * $day,
            'queue-20260901-000000.sqlite' => 40 * $day,
        ] as $name => $age) {
            touch($dir.'/'.$name, $now - $age);
        }

        $deleted = $this->service->prune(14);

        self::assertEqualsCanonicalizing(['fleet-20260901-000000.sqlite', 'fleet-20260920-000000.sqlite'], $deleted);
        self::assertSame(['fleet-20261010-000000.sqlite', 'queue-20260901-000000.sqlite'], $this->names(), 'the only queue backup stays even though it is old');
    }

    public function testPruneKeepsTheLastGoodCopyAfterALongOutage(): void
    {
        touch($this->paths->backupsDir().'/fleet-20260101-000000.sqlite', $this->clock->now()->getTimestamp() - 200 * 86400);

        self::assertSame([], $this->service->prune(14));
        self::assertCount(1, $this->service->list());
    }

    public function testPruneNeverTouchesTheDatabasesARestoreKept(): void
    {
        $old = $this->clock->now()->getTimestamp() - 400 * 86400;
        foreach (['fleet.sqlite.pre-restore-20250101-000000', 'queue.sqlite.pre-restore-20250101-000000', 'fleet-20250101-000000.sqlite'] as $name) {
            touch($this->paths->backupsDir().'/'.$name, $old);
        }
        touch($this->paths->backupsDir().'/fleet-20261018-000000.sqlite', $this->clock->now()->getTimestamp() - 86400);

        self::assertSame(['fleet-20250101-000000.sqlite'], $this->service->prune(14));
        self::assertSame([], $this->service->list(BackupKind::Queue), 'a kept database is not listed as a backup');
        self::assertFileExists($this->paths->backupsDir().'/fleet.sqlite.pre-restore-20250101-000000');
        self::assertFileExists($this->paths->backupsDir().'/queue.sqlite.pre-restore-20250101-000000');
    }

    public function testPruneRejectsARetentionBelowOneDay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->prune(0);
    }

    public function testPathValidatesTheName(): void
    {
        $backup = $this->service->create();
        self::assertSame($this->paths->backupsDir().'/'.$backup->name, $this->service->path($backup->name));

        foreach (['../x', '../fleet-20261019-083015.sqlite', '/etc/passwd', 'fleet-20261019-083015.sqlite/', "fleet-20261019-083015.sqlite\n", 'fleet-20261019-083015.sqlite\0', '..', '', 'fleet-20261019-083016.sqlite', 'fleet-2026-1.sqlite'] as $name) {
            try {
                $this->service->path($name);
                self::fail(\sprintf('"%s" must be rejected', addcslashes($name, "\0..\37")));
            } catch (BackupNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPathRefusesASymlink(): void
    {
        symlink($this->paths->fleetDb(), $this->paths->backupsDir().'/fleet-20261010-010101.sqlite');

        $this->expectException(BackupNotFound::class);
        $this->service->path('fleet-20261010-010101.sqlite');
    }

    private function seed(string $database, string $key, string $value): void
    {
        $pdo = new \PDO('sqlite:'.$database, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kv (k TEXT PRIMARY KEY, v TEXT)');
        $pdo->prepare('INSERT INTO kv VALUES (?, ?)')->execute([$key, $value]);
    }

    private function value(string $database, string $key): ?string
    {
        $pdo = new \PDO('sqlite:'.$database, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->prepare('SELECT v FROM kv WHERE k = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return false === $value ? null : (string) $value;
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_values(array_diff(scandir($this->paths->backupsDir()) ?: [], ['.', '..']));
    }
}
