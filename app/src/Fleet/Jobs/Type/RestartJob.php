<?php

declare(strict_types=1);

namespace App\Fleet\Jobs\Type;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\Job;
use App\Fleet\Ddev\Ddev;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceConfigWriter;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobFeed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;
use App\Fleet\Shell\CommandRunnerException;

/**
 * Rewrites an instance's `.ddev/config.fleet.yaml` from the current settings (the Claude token among them) and runs
 * `ddev restart`, so the container environment picks the new values up. Queued by ClaudeTokenApplier, on request only: the
 * restart ends whatever runs inside the containers, a Claude agent included. The token is read by the config writer
 * when the job runs; it is not a job parameter and never reaches the log.
 */
final readonly class RestartJob implements JobType
{
    public function __construct(
        private InstanceRepository $instances,
        private InstanceConfigWriter $config,
        private Ddev $ddev,
        private FleetPaths $paths,
        private JobFeed $feed,
    ) {
    }

    public function name(): string
    {
        return 'restart';
    }

    public function execute(Job $job, JobLog $log): void
    {
        $instance = null === $job->instanceId() ? null : $this->instances->find($job->instanceId());
        if (null === $instance) {
            throw new JobFailed('instance no longer exists');
        }
        $dir = $this->paths->instanceDir($instance->id());
        if (!is_dir($dir.'/.ddev')) {
            throw new JobFailed('instance directory not found');
        }

        try {
            $log->line('rewriting '.$this->config->write($instance, $instance->project(), $dir));
        } catch (\RuntimeException $e) {
            throw new JobFailed('The instance configuration could not be written: '.$e->getMessage());
        }
        try {
            $result = $this->ddev->restart($dir, $log->output(...));
        } catch (CommandRunnerException $e) {
            throw new JobFailed('ddev restart did not finish: '.$e->getMessage());
        }
        if (!$result->isSuccess()) {
            throw new JobFailed(\sprintf('ddev restart failed with exit code %d', $result->exitCode), $result->exitCode);
        }

        $this->feed->instanceChanged($instance->id());
    }
}
