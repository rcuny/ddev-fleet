<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Command\Support\AttachedJobs;
use App\Command\Support\JsonOutput;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\JobRepository;
use App\Fleet\Instances\InstanceNaming;
use App\Fleet\Projects\NotFound;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:instance:log', description: 'Print the log of an instance\'s latest job (or of --job=<n>)')]
final class LogCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly JobRepository $jobs,
        private readonly AttachedJobs $attached,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Instance id')
            ->addOption('job', null, InputOption::VALUE_REQUIRED, 'Job number (default: the latest job of the instance)')
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Keep printing until the job ends')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $id = (string) $input->getArgument('id');
            $instance = (InstanceNaming::isInstanceId($id) ? $this->instances->find($id) : null) ?? throw NotFound::instance($id);

            $number = $input->getOption('job');
            if (null !== $number) {
                if (!\is_string($number) || 1 !== preg_match('/^[1-9][0-9]{0,9}$/D', $number)) {
                    throw NotFound::job(0);
                }
                $job = $this->jobs->find((int) $number);
                if (null === $job || $job->instanceId() !== $instance->id()) {
                    throw NotFound::job((int) $number);
                }
            } else {
                $job = $this->jobs->forInstance($instance->id(), 1)[0] ?? throw new NotFound(\sprintf('Instance "%s" has no job yet.', $instance->id()));
            }

            $this->attached->echoLog($job, $output, true === $input->getOption('follow'));
        } catch (NotFound $e) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW);

            return JsonOutput::NOT_FOUND;
        }

        return Command::SUCCESS;
    }
}
