<?php

declare(strict_types=1);

namespace App\Command\Support;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Fleet\Jobs\JobExecutor;
use App\Fleet\Jobs\JobService;
use App\Fleet\Jobs\LogReader;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The attached mode of the instance commands: runs the requested jobs one after another in this process (through the
 * same JobExecutor the worker uses, so the UI shows them live), echoes each log, and counts the outcomes. When a worker
 * claimed a job first, this waits for it to end and echoes its log the same way.
 */
final class AttachedJobs
{
    private ?Job $current = null;

    public function __construct(
        private readonly JobExecutor $executor,
        private readonly JobRepository $jobs,
        private readonly JobService $service,
        private readonly LogReader $logs,
    ) {
    }

    /**
     * @param list<Job> $jobs
     *
     * @return array{succeeded: int, failed: int}
     */
    public function run(array $jobs, OutputInterface $output): array
    {
        $count = ['succeeded' => 0, 'failed' => 0];
        foreach ($jobs as $job) {
            $this->current = $job;
            $output->writeln(\sprintf('job %d: %s %s', (int) $job->id(), $job->type(), $job->instanceId() ?? ''), OutputInterface::OUTPUT_RAW);
            $this->executor->runInline((int) $job->id());
            $this->echoLog($job, $output);
            if (JobStatus::Succeeded === $job->status()) {
                ++$count['succeeded'];
            } else {
                ++$count['failed'];
                $output->writeln(\sprintf('job %d %s%s', (int) $job->id(), $job->status()->value, null === $job->error() ? '' : ': '.$job->error()), OutputInterface::OUTPUT_RAW);
            }
        }
        $this->current = null;

        return $count;
    }

    /**
     * What every request command does with the jobs it created: only report them (detach), or run them here and report
     * the outcome. Table mode echoes the logs and prints `N succeeded, M failed`; JSON mode prints one document.
     *
     * @param list<Job>                     $jobs
     * @param (\Closure(Job): ?string)|null $url  the site URL of a succeeded job, printed after the summary
     *
     * @return int the exit code: 0, or 1 / 2 as exitCode() says
     */
    public function handle(array $jobs, bool $detach, JsonOutput $out, OutputInterface $output, ?\Closure $url = null): int
    {
        if ($detach) {
            $out->emit(['jobs' => array_map(self::row(...), $jobs)], static function (JsonOutput $out) use ($jobs): void {
                foreach ($jobs as $job) {
                    $out->success(\sprintf('job %d queued', (int) $job->id()));
                }
            });

            return JsonOutput::OK;
        }

        $count = $this->run($jobs, $out->isJson() ? new NullOutput() : $output);
        $urls = [];
        foreach ($jobs as $job) {
            $site = null !== $url && JobStatus::Succeeded === $job->status() ? $url($job) : null;
            if (null !== $site) {
                $urls[(int) $job->id()] = $site;
            }
        }
        $out->emit(
            ['jobs' => array_map(static fn (Job $job): array => self::row($job) + (isset($urls[(int) $job->id()]) ? ['url' => $urls[(int) $job->id()]] : []), $jobs)] + $count,
            static function (JsonOutput $out) use ($count, $urls): void {
                $out->success(\sprintf('%d succeeded, %d failed', $count['succeeded'], $count['failed']));
                foreach ($urls as $site) {
                    $out->success($site);
                }
            },
        );

        return self::exitCode($count);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Job $job): array
    {
        return ['id' => $job->id(), 'type' => $job->type(), 'instance' => $job->instanceId(), 'status' => $job->status()->value, 'error' => $job->error()];
    }

    /**
     * Ends the job being run as interrupted (SIGINT); the instance lock goes with the process.
     */
    public function interrupt(): void
    {
        $job = $this->current;
        if (null === $job) {
            return;
        }
        $this->jobs->refresh($job);
        if (!$job->status()->isFinal()) {
            $this->service->finish($job, JobStatus::Interrupted, null, 'Interrupted from the command line.');
        }
    }

    /**
     * Exit code of a run: 0 all succeeded, 1 all failed, 2 some failed.
     *
     * @param array{succeeded: int, failed: int} $count
     */
    public static function exitCode(array $count): int
    {
        if (0 === $count['failed']) {
            return JsonOutput::OK;
        }

        return 0 === $count['succeeded'] ? JsonOutput::INVALID : JsonOutput::REFUSED;
    }

    /**
     * Prints a job's whole log, following it while the job is still running somewhere else.
     */
    public function echoLog(Job $job, OutputInterface $output, bool $follow = true): void
    {
        $from = 0;
        while (true) {
            $this->jobs->refresh($job);
            $piece = $this->logs->read($job, $from);
            if ('' !== $piece['text']) {
                $output->write($piece['text'], false, OutputInterface::OUTPUT_RAW);
            }
            $from = $piece['next'];
            if ($piece['done'] || !$follow) {
                return;
            }
            usleep(500_000);
        }
    }
}
