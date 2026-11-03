<?php

declare(strict_types=1);

namespace App\Command\Schedule;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\Refused;
use App\Fleet\Schedules\ScheduleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:schedule:delete', description: 'Delete a schedule and its history')]
final class ScheduleDeleteCommand extends Command
{
    public function __construct(private readonly ScheduleService $schedules)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Schedule name');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::schedule());

        return $out->run(function () use ($input, $out): void {
            $name = (string) $input->getArgument('name');
            $this->schedules->get($name);
            if (!$out->confirmed($input, \sprintf('Delete schedule "%s" and its history?', $name))) {
                throw new Refused('Not deleted: pass --yes to confirm.');
            }
            $this->schedules->delete($name, AuditLog::CLI_ACTOR);
            $out->emit(['deleted' => $name], static function (JsonOutput $out) use ($name): void {
                $out->success(\sprintf('Schedule "%s" deleted.', $name));
            });
        });
    }
}
