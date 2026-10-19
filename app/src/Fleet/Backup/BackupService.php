<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

use App\Fleet\FleetPaths;
use Psr\Clock\ClockInterface;

/**
 * Consistent copies of the two SQLite databases in FLEET_HOME/backups (owner only, files 0600).
 *
 * A copy is made with `VACUUM INTO` on a separate read handle, so it is a point-in-time snapshot even while php-fpm
 * and the workers write (WAL), it is never a half-copied file, and it needs no stopped service. The snapshot is
 * checked, then renamed into place atomically. `fleet.sqlite` holds password hashes and sealed secrets: the key that
 * opens the secrets (`keys/secret.key`) is deliberately NOT part of a backup.
 */
final readonly class BackupService
{
    /** The only names this service writes, lists, serves or deletes. */
    /**
     * "Back up now" runs inside the php-fpm request (`max_execution_time`), so it refuses databases above this size
     * (fleet.sqlite plus queue.sqlite); the daily timer and `fleet fleet:backup:create` have no such limit.
     */
    public const int INTERACTIVE_MAX_BYTES = 256 * 1024 * 1024;

    private const string NAME_PATTERN = '/^(fleet|queue)-(\d{8})-(\d{6})\.sqlite$/D';

    public function __construct(
        private FleetPaths $paths,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Backs up fleet.sqlite and, when it exists, queue.sqlite (same timestamp). Returns the fleet backup.
     *
     * @throws \RuntimeException when the database is missing or a copy fails; nothing partial is left behind
     */
    public function create(): BackupInfo
    {
        $this->paths->ensureLayout();
        $stamp = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-His');

        $fleet = $this->snapshot($this->paths->fleetDb(), BackupKind::Fleet, $stamp);
        if (is_file($this->paths->queueDb())) {
            $this->snapshot($this->paths->queueDb(), BackupKind::Queue, $stamp);
        }

        return $fleet;
    }

    /**
     * Like create(), for a web request: refuses a database too large to copy within the request's time limit.
     *
     * @throws BackupTooLarge    nothing was written
     * @throws \RuntimeException as create()
     */
    public function createWithinRequestLimit(int $maxBytes = self::INTERACTIVE_MAX_BYTES): BackupInfo
    {
        $size = 0;
        foreach ([$this->paths->fleetDb(), $this->paths->queueDb()] as $database) {
            $size += is_file($database) ? (int) @filesize($database) : 0;
        }
        if ($size > $maxBytes) {
            throw new BackupTooLarge($size, $maxBytes);
        }

        return $this->create();
    }

    /**
     * @return list<BackupInfo> newest first (ties: name descending), only files with a strict backup name
     */
    public function list(?BackupKind $kind = null): array
    {
        $infos = [];
        foreach (@scandir($this->paths->backupsDir()) ?: [] as $name) {
            $info = $this->info($name);
            if (null !== $info && (null === $kind || $info->kind === $kind)) {
                $infos[] = $info;
            }
        }
        usort($infos, static fn (BackupInfo $a, BackupInfo $b): int => [$b->modifiedAt->getTimestamp(), $b->name] <=> [$a->modifiedAt->getTimestamp(), $a->name]);

        return $infos;
    }

    /**
     * Deletes the backups older than $retentionDays (by modification time). The newest backup of each kind is always
     * kept, so a long outage of the timer can never prune the last good copy.
     *
     * @return list<string> the deleted names
     */
    public function prune(int $retentionDays): array
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('The retention must be at least 1 day.');
        }
        $cutoff = $this->clock->now()->getTimestamp() - $retentionDays * 86400;

        $deleted = [];
        $kept = [];
        foreach ($this->list() as $info) {
            if (!isset($kept[$info->kind->value])) {
                $kept[$info->kind->value] = true;
                continue;
            }
            if ($info->modifiedAt->getTimestamp() < $cutoff && @unlink($this->paths->backupsDir().'/'.$info->name)) {
                $deleted[] = $info->name;
            }
        }

        return $deleted;
    }

    /**
     * The absolute path of an existing backup. The name is checked against the strict pattern first, so no
     * separator, dot segment or other file can get through.
     *
     * @throws BackupNotFound
     */
    public function path(string $name): string
    {
        if (null === $this->info($name)) {
            throw new BackupNotFound('No such backup.');
        }

        return $this->paths->backupsDir().'/'.$name;
    }

    private function info(string $name): ?BackupInfo
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name, $m)) {
            return null;
        }
        $path = $this->paths->backupsDir().'/'.$name;
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $size = @filesize($path);
        $mtime = @filemtime($path);
        if (false === $size || false === $mtime) {
            return null;
        }

        return new BackupInfo($name, BackupKind::from($m[1]), $size, new \DateTimeImmutable('@'.$mtime));
    }

    private function snapshot(string $database, BackupKind $kind, string $stamp): BackupInfo
    {
        $name = \sprintf('%s-%s.sqlite', $kind->value, $stamp);
        $target = $this->paths->backupsDir().'/'.$name;
        $tmp = $this->paths->backupsDir().'/.tmp-'.bin2hex(random_bytes(6));

        if (!is_file($database)) {
            throw new \RuntimeException(\sprintf('The %s database "%s" does not exist.', $kind->value, $database));
        }

        try {
            try {
                $pdo = new \PDO('sqlite:'.$database, null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 30,
                ]);
                // Not a transaction: VACUUM INTO refuses to run inside one.
                $pdo->prepare('VACUUM INTO ?')->execute([$tmp]);
                unset($pdo);

                $check = new \PDO('sqlite:'.$tmp, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $statement = $check->query('PRAGMA quick_check');
                $verdict = false === $statement ? false : $statement->fetchColumn();
                unset($statement, $check);
                if ('ok' !== $verdict) {
                    throw new \RuntimeException('the copy failed its integrity check');
                }
            } catch (\PDOException $e) {
                throw new \RuntimeException(\sprintf('Cannot back up the %s database: %s', $kind->value, $e->getMessage()), 0, $e);
            }
            if (!@chmod($tmp, 0o600)) {
                throw new \RuntimeException(\sprintf('Cannot set mode 0600 on the %s backup.', $kind->value));
            }
            if (!@rename($tmp, $target)) {
                throw new \RuntimeException(\sprintf('Cannot move the %s backup into place.', $kind->value));
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $info = $this->info($name);
        if (null === $info) {
            throw new \RuntimeException(\sprintf('The %s backup "%s" is missing after it was written.', $kind->value, $name));
        }

        return $info;
    }
}
