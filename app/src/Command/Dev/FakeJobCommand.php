<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Fleet\Jobs\JobExecutor;
use App\Fleet\Jobs\JobFeed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Development/test only: a growing log without a worker. Creates a job for an existing instance, runs it in this
 * process (it takes the instance lock and the job is `running` while the lines arrive) and ends it, failed with --fail.
 * The Playwright specs use it to watch a live log and Retry.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'fleet:dev:fake-job', description: 'Run a fake job with a growing log on an instance (dev and test only)')]
final class FakeJobCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly JobRepository $jobs,
        private readonly JobExecutor $executor,
        private readonly JobFeed $feed,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('instance-id', InputArgument::REQUIRED, 'An existing instance id')
            ->addOption('lines', null, InputOption::VALUE_REQUIRED, 'Number of log lines', '30')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Milliseconds between two lines', '200')
            ->addOption('width', null, InputOption::VALUE_REQUIRED, 'Pad each line to this many characters', '0')
            ->addOption('fail', null, InputOption::VALUE_NONE, 'End the job as failed')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $instance = $this->instances->find((string) $input->getArgument('instance-id'));
        if (null === $instance) {
            $output->writeln('No such instance.');

            return Command::FAILURE;
        }

        $job = new Job('fake', $instance->id(), $instance->project()->name(), JobTrigger::Cli, 'cli:fake-job', [
            'lines' => (int) $input->getOption('lines'),
            'delay' => (int) $input->getOption('delay'),
            'width' => (int) $input->getOption('width'),
            'fail' => true === $input->getOption('fail'),
        ]);
        $this->jobs->add($job);
        $this->feed->jobChanged($job, 0);
        $this->feed->instanceChanged($instance->id());

        $done = $this->executor->runInline((int) $job->id());
        $output->writeln(\sprintf('job %d: %s', (int) $job->id(), $done->status()->value));

        return Command::SUCCESS;
    }
}
