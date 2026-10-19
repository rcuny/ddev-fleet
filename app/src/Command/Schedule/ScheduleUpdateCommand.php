<?php

declare(strict_types=1);

namespace App\Command\Schedule;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Schedules\ScheduleInput;
use App\Fleet\Schedules\ScheduleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:schedule:update', description: 'Change the timing, action or instance of a schedule (not its name)')]
final class ScheduleUpdateCommand extends Command
{
    public function __construct(private readonly ScheduleService $schedules, private readonly ScheduleShapes $shapes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Schedule name');
        $this->addOption('cron', null, InputOption::VALUE_REQUIRED, 'Five-field cron expression, for example "0 3 * * *" (required)');
        $this->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'IANA timezone', 'UTC');
        $this->addOption('action', null, InputOption::VALUE_REQUIRED, 'What to run', 'redeploy');
        $this->addOption('instance', null, InputOption::VALUE_REQUIRED, 'Id of the instance the action applies to, for example demo--main');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $in, OutputInterface $output): int
    {
        $out = JsonOutput::of($in, $output, FieldErrors::schedule());

        return $out->run(function () use ($in, $out): void {
            $input = new ScheduleInput((string) $in->getArgument('name'), (string) $in->getOption('cron'), (string) $in->getOption('timezone'), (string) $in->getOption('action'), $in->getOption('instance'));
            $by = AuditLog::CLI_ACTOR;
            $schedule = $this->schedules->update((string) $in->getArgument('name'), $input, $by);
            $out->emit($this->shapes->schedule($schedule), static function (JsonOutput $out) use ($schedule): void {
                $out->success(\sprintf('Schedule "%s" updated (%s, %s).', $schedule->name(), $schedule->cron(), $schedule->timezone()));
            });
        });
    }
}
