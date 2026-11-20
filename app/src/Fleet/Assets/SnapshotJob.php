<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Fleet\Ddev\Ddev;
use App\Fleet\FleetPaths;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Shell\CommandResult;
use Psr\Clock\ClockInterface;

/**
 * Dumps a running instance's database into its project's assets directory: `ddev export-db` writes a gzip file next to
 * its target (a hidden upload temporary, which the listing hides and `fleet asset:gc` sweeps if the process is killed),
 * then AssetStore::publish() links it into `dumps/default-<instance>-<UTC time>.sql.gz` without ever replacing a file.
 * Nothing partial is ever visible under that name.
 */
final readonly class SnapshotJob implements JobType
{
    public const string NAME = 'snapshot';
    public const string FOLDER = 'dumps';
    /** A dump is written to the project's filesystem: refuse to start with less room than this. */
    public const int MIN_FREE_BYTES = 536_870_912;
    private const int NAME_ATTEMPTS = 20;

    public function __construct(
        private InstanceRepository $instances,
        private AssetStore $store,
        private Ddev $ddev,
        private FleetPaths $paths,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function execute(Job $job, JobLog $log): void
    {
        $id = $job->instanceId() ?? throw new JobFailed('The snapshot job names no instance.');
        $instance = $this->instances->find($id) ?? throw new JobFailed('The instance does not exist.');
        if (InstanceState::Running !== $instance->state()) {
            throw new JobFailed('The instance is not running: start it before taking a snapshot.');
        }
        $project = $instance->project()->name();
        $dir = $this->paths->instanceDir($id);
        if (!is_dir($dir)) {
            throw new JobFailed('The instance has no checkout on disk.');
        }

        try {
            [$directory, $target] = $this->freeTarget($project, $id);
            $this->ensureRoom($directory);
        } catch (Refused|ValidationFailed $e) {
            throw new JobFailed($e instanceof ValidationFailed ? implode(' ', $e->errors) : $e->getMessage());
        }

        $temporary = $directory.'/'.AssetPath::TEMP_PREFIX.bin2hex(random_bytes(8)).'.tmp';
        try {
            $log->line(\sprintf('Exporting the database of %s with ddev export-db.', $id));
            $result = $this->ddev->run($dir, ['ddev', 'export-db', '--gzip', '--file='.$temporary], [], $log->output(...));
            if (!$result->isSuccess()) {
                // FLE-355 / FLE-295: export-db runs the dump without credentials and fails where the db container has no
                // client config. Fall back to DDEV's own database account (db/db), dumping from the web container.
                $log->line(\sprintf('ddev export-db failed (exit %d); retrying with the DDEV database credentials.', $result->exitCode));
                @unlink($temporary);
                $result = $this->dumpWithCredentials($dir, $temporary, $log);
            }
            if (!$result->isSuccess()) {
                throw new JobFailed(\sprintf('ddev export-db failed (exit %d): see the job log.', $result->exitCode), $result->exitCode);
            }
            $size = is_file($temporary) ? filesize($temporary) : false;
            if (false === $size || 0 === $size) {
                throw new JobFailed('ddev export-db produced no dump.');
            }

            try {
                $this->store->publish($temporary, $target, false);
            } catch (Refused $e) {
                throw new JobFailed($e->getMessage());
            }
            $log->line(\sprintf('Saved %s/%s (%s).', self::FOLDER, basename($target), ByteSize::format($size)));
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Dumps through the web container (the checkout is mounted there) into a hidden file of the checkout, then moves it.
     * The account comes from `ddev describe` (DDEV's db/db by default). The password reaches the dump client through the
     * environment (MYSQL_PWD, PGPASSWORD) from a 0600 file of the checkout that the script sources and removes: it is
     * never in argv, so neither `ps` nor the job log shows it.
     */
    private function dumpWithCredentials(string $dir, string $temporary, JobLog $log): CommandResult
    {
        $info = $this->ddev->databaseInfo($dir);
        $token = bin2hex(random_bytes(8));
        $inside = '.fleet-snapshot-'.$token.'.sql.gz';
        $envName = '.fleet-snapshot-'.$token.'.env';
        $envFile = $dir.'/'.$envName;
        $file = $dir.'/'.$inside;

        $variable = $info->isPostgres() ? 'PGPASSWORD' : 'MYSQL_PWD';
        $port = $info->port > 0 ? $info->port : ($info->isPostgres() ? 5432 : 3306);
        if ($info->isPostgres()) {
            $dump = \sprintf('pg_dump -h %s -p %d -U %s --no-owner %s', $info->host, $port, $info->user, $info->name);
            $client = 'command -v pg_dump >/dev/null';
        } else {
            $dump = \sprintf('"$d" -h %s -P %d -u%s --single-transaction --routines %s', $info->host, $port, $info->user, $info->name);
            $client = 'd=$(command -v mariadb-dump || command -v mysqldump)';
        }
        $script = \sprintf('. ./%s; rm -f ./%s; set -o pipefail; %s && %s | gzip > %s', $envName, $envName, $client, $dump, $inside);

        try {
            $log->line(\sprintf('Dumping with the database account %s on %s (%s).', $info->user, $info->host, $info->isPostgres() ? 'pg_dump' : 'mariadb-dump'));
            $old = umask(0o077);
            try {
                $written = @file_put_contents($envFile, 'export '.$variable.'='."'".str_replace("'", "'\\''", $info->password)."'\n");
            } finally {
                umask($old);
            }
            if (false === $written) {
                $log->line('Could not hand the database credentials to the web container.');

                return new CommandResult(1);
            }
            $result = $this->ddev->run($dir, ['ddev', 'exec', $script], [], $log->output(...));
            if ($result->isSuccess() && is_file($file) && !@copy($file, $temporary)) {
                $log->line('Could not copy the dump out of the checkout.');

                return new CommandResult(1);
            }
        } finally {
            @unlink($file);
            @unlink($envFile);
        }

        return $result;
    }

    /**
     * The first `default-<instance>-<time>[-n].sql.gz` that does not exist yet (two snapshots in one second differ by
     * the suffix); the publish step still refuses a file that appeared since.
     *
     * @return array{string, string} [directory, target file]
     *
     * @throws Refused
     * @throws ValidationFailed
     */
    private function freeTarget(string $project, string $instance): array
    {
        $stem = \sprintf('default-%s-%s', $instance, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
        for ($n = 1; $n <= self::NAME_ATTEMPTS; ++$n) {
            $name = $stem.(1 === $n ? '' : '-'.$n).'.sql.gz';
            try {
                return $this->store->prepareTarget($project, self::FOLDER, $name, false);
            } catch (Refused $e) {
                if (AssetStore::REASON_FILE_EXISTS !== $e->reason) {
                    throw $e;
                }
            }
        }

        throw new Refused('A snapshot of this instance was taken in the same second: try again.');
    }

    /**
     * @throws Refused
     */
    private function ensureRoom(string $directory): void
    {
        $free = @disk_free_space($directory);
        if (false !== $free && $free < self::MIN_FREE_BYTES) {
            throw new Refused(\sprintf('There is not enough free disk space for a dump (%s needed).', ByteSize::format(self::MIN_FREE_BYTES)));
        }
    }
}
