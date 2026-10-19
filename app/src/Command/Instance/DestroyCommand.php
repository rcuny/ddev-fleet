<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Command\Support\AttachedCommand;
use App\Command\Support\AttachedJobs;
use App\Command\Support\CliRequester;
use App\Command\Support\FieldErrors;
use App\Command\Support\InstanceOptions;
use App\Command\Support\JsonOutput;
use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Instances\OperationOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:instance:destroy', description: 'Destroy instances: their containers, database and checkout')]
final class DestroyCommand extends AttachedCommand
{
    public function __construct(AttachedJobs $attached, private readonly InstanceService $instances)
    {
        parent::__construct($attached);
    }

    protected function configure(): void
    {
        InstanceOptions::addSelection($this);
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirm a selector or several ids');
        self::addDetach($this);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::instance());

        return self::guarded($out, function () use ($input, $output, $out): int {
            $selector = InstanceOptions::selector($input);
            InstanceOptions::confirm($input, $output, $out, $selector, \count($this->instances->resolve($selector)));
            $jobs = $this->instances->request('destroy', $selector, new OperationOptions(), JobTrigger::Cli, CliRequester::name());

            return $this->attached->handle($jobs, true === $input->getOption('detach'), $out, $output);
        });
    }
}
