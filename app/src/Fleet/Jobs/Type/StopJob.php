<?php

declare(strict_types=1);

namespace App\Fleet\Jobs\Type;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Fleet\Ddev\Ddev;
use App\Fleet\FleetPaths;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobFeed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;
use App\Fleet\Shell\CommandRunnerException;
use Psr\Clock\ClockInterface;

/**
 * Stops an instance and frees its memory; the checkout stays. Params: `timeout` (seconds, optional).
 */
final readonly class StopJob implements JobType
{
    public function __construct(
        private InstanceRepository $instances,
        private Ddev $ddev,
        private FleetPaths $paths,
        private JobFeed $feed,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'stop';
    }

    public function execute(Job $job, JobLog $log): void
    {
        $instance = null === $job->instanceId() ? null : $this->instances->find($job->instanceId());
        if (null === $instance) {
            throw new JobFailed('instance no longer exists');
        }
        $dir = $this->paths->instanceDir($instance->id());
        if (!is_dir($dir)) {
            throw new JobFailed('instance directory not found');
        }

        $params = $job->params();
        $timeout = \is_int($params['timeout'] ?? null) || \is_float($params['timeout'] ?? null) ? (float) $params['timeout'] : null;

        try {
            $result = $this->ddev->stop($dir, $log->output(...), $timeout);
        } catch (CommandRunnerException $e) {
            throw new JobFailed('ddev stop did not finish: '.$e->getMessage());
        }
        if (!$result->isSuccess()) {
            throw new JobFailed(\sprintf('ddev stop failed with exit code %d', $result->exitCode), $result->exitCode);
        }

        $instance->changeState(InstanceState::Stopped, $this->clock->now());
        $this->instances->save($instance);
        $this->feed->instanceChanged($instance->id());
    }
}
