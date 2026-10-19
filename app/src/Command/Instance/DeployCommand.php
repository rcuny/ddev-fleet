<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Command\Support\AttachedCommand;
use App\Command\Support\AttachedJobs;
use App\Command\Support\CliRequester;
use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Job\Job;
use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\DeployInput;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:instance:deploy', description: 'Deploy one or more instances of a project')]
final class DeployCommand extends AttachedCommand
{
    public function __construct(
        AttachedJobs $attached,
        private readonly InstanceService $instances,
        private readonly InstanceHostnames $hostnames,
    ) {
        parent::__construct($attached);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'Project name')
            ->addArgument('template', InputArgument::OPTIONAL, 'Template name (default: the project\'s default template)')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Branch to deploy (default: the project\'s default branch)')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Label of the instance (default: the branch)')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many instances', '1')
            ->addOption('parallel', null, InputOption::VALUE_NONE, 'Deploy a batch concurrently instead of one after another')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace a leftover checkout of the same name')
            ->addOption('skip-disk-check', null, InputOption::VALUE_NONE, 'Do not check the free disk space of a batch')
        ;
        self::addDetach($this);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::instance());

        $count = $input->getOption('count');
        if (!\is_string($count) || 1 !== preg_match('/^[0-9]{1,6}$/D', $count)) {
            return $out->fail('The count must be a whole number.', JsonOutput::INVALID, ['--count' => 'The count must be a whole number.']);
        }
        if (0 === (int) $count) {
            $out->emit(['jobs' => []], static function (JsonOutput $out): void {
                $out->success('nothing to deploy');
            });

            return JsonOutput::OK;
        }

        return self::guarded($out, function () use ($input, $output, $out, $count): int {
            $in = new DeployInput(
                (string) $input->getArgument('project'),
                self::text($input->getArgument('template')),
                self::text($input->getOption('branch')),
                self::text($input->getOption('label')),
                (int) $count,
                true === $input->getOption('force'),
                true === $input->getOption('parallel'),
                true === $input->getOption('skip-disk-check'),
            );
            $jobs = $this->instances->requestDeploy($in, JobTrigger::Cli, CliRequester::name());

            return $this->attached->handle(
                $jobs,
                true === $input->getOption('detach'),
                $out,
                $output,
                fn (Job $job): ?string => null === $job->instanceId() ? null : $this->hostnames->siteUrl($job->instanceId()),
            );
        });
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
