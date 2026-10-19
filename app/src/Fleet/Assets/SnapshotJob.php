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
