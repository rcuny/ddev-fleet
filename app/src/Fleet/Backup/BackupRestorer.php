<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

use App\Domain\Job\JobRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use Psr\Clock\ClockInterface;

/**
 * Puts a backup made by BackupService back as the live database, safely and reversibly.
 *
 * preflight() decides without touching anything: the backup (and its queue sibling) pass BackupVerifier, no job is
 * queued or running, no worker or scheduler unit is active. restore() then copies the backup next to the live file
 * (same filesystem), keeps the current database as `<name>.sqlite.pre-restore-<UTC stamp>` in the backups directory
 * (BackupService never lists, serves or prunes that name), and renames the copy over the live file. Whatever fails
 * after the first rename, including the $migrate step, puts the previous databases back. The units are stopped and
 * started by the operator with `sudo systemctl`: no new root surface.
 */
final readonly class BackupRestorer
{
    /** `fleet-<stamp>.sqlite` (a backup) or `fleet.sqlite.pre-restore-<stamp>` (what a restore kept). */
    private const string FLEET_NAME = '/^fleet(?|-(\d{8}-\d{6})\.sqlite|\.sqlite\.pre-restore-(\d{8}-\d{6}))$/D';
    private const string UNIT_NAME = '/^fleet-(?:worker@[A-Za-z0-9_.:-]+|scheduler)\.service$/D';

    public function __construct(
        private FleetPaths $paths,
        private BackupVerifier $verifier,
        private JobRepository $jobs,
        private CommandRunner $runner,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the path is not a fleet backup file to look at (missing, a link, the live database, another name)
     */
    public function preflight(string $file): RestorePlan
    {
        $real = realpath($file);
        if (false === $real || !is_file($real)) {
            throw new \InvalidArgumentException('The backup file does not exist or cannot be read.');
        }
        if (1 !== preg_match(self::FLEET_NAME, basename($real), $m)) {
            throw new \InvalidArgumentException('Restore a fleet database backup: fleet-YYYYmmdd-HHMMSS.sqlite (or a fleet.sqlite.pre-restore-* file).');
        }
        $stamp = $m[1];
        $pre = str_contains(basename($real), '.pre-restore-');

        $verification = $this->verifier->verify($file);
        $refusals = [];
        foreach ($verification->problems as $problem) {
            $refusals[] = $problem;
        }

        $queue = \dirname($real).'/'.($pre ? 'queue.sqlite.pre-restore-'.$stamp : 'queue-'.$stamp.'.sqlite');
        $queueBackup = is_file($queue) && !is_link($queue) ? $queue : null;
        if (null !== $queueBackup) {
            foreach ($this->verifier->verify($queueBackup)->problems as $problem) {
                $refusals[] = 'queue backup: '.$problem;
            }
        }

        $refusals = [...$refusals, ...$this->jobRefusals()];
        [$unitRefusals, $units] = $this->unitRefusals();

        return new RestorePlan($real, $queueBackup, $verification, [...$refusals, ...$unitRefusals], $units);
    }

    /**
     * @param callable(): void $migrate brings the restored fleet database up to this release and validates its schema;
     *                                  throws when it cannot (the previous databases are then put back)
     *
     * @throws RestoreFailed
     */
    public function restore(RestorePlan $plan, callable $migrate): RestoreResult
    {
        if (!$plan->ok()) {
            throw new \LogicException('The preflight refused this restore.');
        }
        $this->paths->ensureLayout();
        $stamp = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-His');

        /** @var array<string, array{live: string, source: string, kept: string|null}> $steps */
        $steps = ['fleet' => ['live' => $this->paths->fleetDb(), 'source' => $plan->fleetBackup, 'kept' => null]];
        if (null !== $plan->queueBackup) {
            $steps['queue'] = ['live' => $this->paths->queueDb(), 'source' => $plan->queueBackup, 'kept' => null];
        }
        foreach ($steps as $kind => $step) {
            $kept = $this->paths->backupsDir().'/'.$kind.'.sqlite.pre-restore-'.$stamp;
            if (file_exists($kept) || is_link($kept)) {
                throw new RestoreFailed(\sprintf('"%s" already exists: wait a second and run the restore again.', basename($kept)), true);
            }
            $steps[$kind]['kept'] = is_file($step['live']) ? $kept : null;
        }

        // Everything that can fail without harm comes first: the live databases are untouched until the renames.
        $staged = [];
        try {
            foreach ($steps as $kind => $step) {
                $staged[$kind] = $this->stage($step['source'], $step['live']);
                if (null !== $step['kept']) {
                    $this->keep($step['live'], $step['kept']);
                }
            }
        } catch (\Throwable $e) {
            foreach ($staged as $path) {
                @unlink($path);
            }
            // The kept copies are harmless leftovers of a restore that never started; remove only what this run wrote.
            foreach ($steps as $step) {
                if (null !== $step['kept'] && is_file($step['kept'])) {
                    @unlink($step['kept']);
                }
            }
            throw new RestoreFailed('Nothing was changed: '.$e->getMessage(), true, $e);
        }

        $swapped = [];
        try {
            foreach ($steps as $kind => $step) {
                $this->dropJournals($step['live']);
                if (!@rename($staged[$kind], $step['live'])) {
                    throw new \RuntimeException(\sprintf('Cannot move the restored %s database into place.', $kind));
                }
                unset($staged[$kind]);
                $swapped[$kind] = $step;
            }
            $migrate();
        } catch (\Throwable $e) {
            foreach ($staged as $path) {
                @unlink($path);
            }
            $this->rollBack($swapped, $e);
        }

        return new RestoreResult(
            $plan->fleetBackup,
            $plan->queueBackup,
            $steps['fleet']['kept'],
            $steps['queue']['kept'] ?? null,
        );
    }

    /**
     * @return list<string>
     */
    private function jobRefusals(): array
    {
        // A host whose fleet database is gone has no jobs, and opening it would create an empty file.
        if (!is_file($this->paths->fleetDb())) {
            return [];
        }
        try {
            $active = $this->jobs->countActive();
        } catch (\Throwable) {
            return ['Cannot read the jobs of the live database, so a running job cannot be ruled out. If the file is damaged, move it aside first (docs/installation.md, "Backup and restore").'];
        }

        return 0 === $active ? [] : [\sprintf('%d job(s) are queued or running: wait for them (see "fleet job:list") before restoring.', $active)];
    }

    /**
     * @return array{list<string>, list<string>} the refusals and the active unit names
     */
    private function unitRefusals(): array
    {
        try {
            $result = $this->runner->run(
                ['systemctl', 'list-units', '--plain', '--no-legend', '--no-pager', '--state=active,activating,reloading,deactivating', 'fleet-worker@*.service', 'fleet-scheduler.service'],
                null,
                [],
                null,
                15.0,
            );
        } catch (CommandRunnerException $e) {
            return [['Cannot check the fleet units: '.$e->getMessage()], []];
        }
        if (127 === $result->exitCode) {
            // No systemctl, so no units (a dev checkout).
            return [[], []];
        }
        if (!$result->isSuccess()) {
            return [['Cannot check the fleet units: systemctl exited '.$result->exitCode.'.'], []];
        }

        $units = [];
        foreach (preg_split('/\R/', $result->stdout, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $name = preg_split('/\s+/', trim($line))[0] ?? '';
            if (1 === preg_match(self::UNIT_NAME, $name)) {
                $units[] = $name;
            }
        }
        sort($units);

        return [[] === $units ? [] : [\sprintf('Still running: %s. Stop them first.', implode(', ', $units))], $units];
    }

    /**
     * Copies a backup next to the live file (same directory, so the rename is atomic), mode 0600, flushed to disk.
     */
    private function stage(string $source, string $live): string
    {
        $tmp = \dirname($live).'/.restore-'.bin2hex(random_bytes(6)).'.tmp';
        try {
            if (!@copy($source, $tmp) || !@chmod($tmp, 0o600)) {
                throw new \RuntimeException('Cannot copy the backup next to the live database.');
            }
            if (hash_file('sha256', $source) !== hash_file('sha256', $tmp)) {
                throw new \RuntimeException('The copy differs from the backup.');
            }
            $this->sync($tmp);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        return $tmp;
    }

    /**
     * Keeps the live database as a consistent snapshot (`VACUUM INTO` sees the WAL), flushed and mode 0600.
     */
    private function keep(string $live, string $target): void
    {
        $tmp = \dirname($target).'/.tmp-'.bin2hex(random_bytes(6));
        try {
            try {
                $pdo = new \PDO('sqlite:'.$live, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 30]);
                $pdo->prepare('VACUUM INTO ?')->execute([$tmp]);
                unset($pdo);
            } catch (\PDOException $e) {
                throw new \RuntimeException('Cannot keep the current database (move a damaged file aside first): '.$e->getMessage(), 0, $e);
            }
            if (!@chmod($tmp, 0o600)) {
                throw new \RuntimeException('Cannot set mode 0600 on the kept database.');
            }
            $this->sync($tmp);
            if (!@rename($tmp, $target)) {
                throw new \RuntimeException('Cannot move the kept database into place.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * A leftover `-wal` would be replayed onto the database that replaces the file, so it goes before the rename.
     */
    private function dropJournals(string $live): void
    {
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($live.$suffix) && !@unlink($live.$suffix)) {
                throw new \RuntimeException(\sprintf('Cannot remove "%s".', basename($live.$suffix)));
            }
        }
    }

    private function sync(string $path): void
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open a file to flush it.');
        }
        $ok = @fsync($handle);
        fclose($handle);
        if (!$ok) {
            throw new \RuntimeException('Cannot flush a file to disk.');
        }
    }

    /**
     * @param array<string, array{live: string, source: string, kept: string|null}> $swapped
     *
     * @throws RestoreFailed always
     */
    private function rollBack(array $swapped, \Throwable $cause): never
    {
        $stuck = [];
        foreach (array_reverse($swapped, true) as $kind => $step) {
            try {
                if (null === $step['kept']) {
                    $this->dropJournals($step['live']);
                    @unlink($step['live']);
                    continue;
                }
                $tmp = $this->stage($step['kept'], $step['live']);
                $this->dropJournals($step['live']);
                if (!@rename($tmp, $step['live'])) {
                    @unlink($tmp);
                    throw new \RuntimeException('rename failed');
                }
            } catch (\Throwable) {
                $stuck[] = \sprintf('%s: copy "%s" back to "%s"', $kind, $step['kept'] ?? '(none)', $step['live']);
            }
        }
        if ([] !== $stuck) {
            throw new RestoreFailed(\sprintf('The restore failed (%s) and the previous database could NOT be put back automatically. It is kept; do by hand: %s', $cause->getMessage(), implode('; ', $stuck)), false, $cause);
        }

        throw new RestoreFailed(\sprintf('The restore failed (%s). The previous database is live again.', $cause->getMessage()), true, $cause);
    }
}
