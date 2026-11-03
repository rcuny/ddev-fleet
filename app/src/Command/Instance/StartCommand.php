<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Command\Support\AttachedCommand;
use App\Command\Support\AttachedJobs;
use App\Command\Support\InstanceOptions;
use App\Command\Support\JsonOutput;
use App\Fleet\Instances\InstanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:instance:start', description: 'Start stopped instances')]
final class StartCommand extends AttachedCommand
{
    public function __construct(AttachedJobs $attached, private readonly InstanceService $instances)
    {
        parent::__construct($attached);
    }

    protected function configure(): void
    {
        InstanceOptions::addSelection($this);
        $this
            ->addOption('sequential', null, InputOption::VALUE_NONE, 'Run a bulk request one instance at a time')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds ddev may take')
            ->addOption('retry-port-conflict', null, InputOption::VALUE_NONE, 'Stop and start again once when ddev reports a port conflict')
        ;
        self::addDetach($this);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return StartStop::run($input, $output, 'start', $this->instances, $this->attached);
    }
}
