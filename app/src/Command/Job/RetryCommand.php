<?php

declare(strict_types=1);

namespace App\Command\Job;

use App\Command\Support\AttachedCommand;
use App\Command\Support\AttachedJobs;
use App\Command\Support\CliRequester;
use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Projects\NotFound;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:job:retry', description: 'Repeat a failed or interrupted job as a new job')]
final class RetryCommand extends AttachedCommand
{
    public function __construct(AttachedJobs $attached, private readonly InstanceService $instances)
    {
        parent::__construct($attached);
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Job number');
        self::addDetach($this);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, new FieldErrors(['id']));

        return self::guarded($out, function () use ($input, $output, $out): int {
            $number = (string) $input->getArgument('id');
            if (1 !== preg_match('/^[1-9][0-9]{0,9}$/D', $number)) {
                throw NotFound::job(0);
            }
            $job = $this->instances->retry((int) $number, JobTrigger::Cli, CliRequester::name());

            return $this->attached->handle([$job], true === $input->getOption('detach'), $out, $output);
        });
    }
}
