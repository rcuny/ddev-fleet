<?php

declare(strict_types=1);

namespace App\Command\Schedule;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Schedules\ScheduleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:schedule:pause', description: 'Stop a schedule from firing (run now still works)')]
final class SchedulePauseCommand extends Command
{
    public function __construct(private readonly ScheduleService $schedules, private readonly ScheduleShapes $shapes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Schedule name');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::schedule());

        return $out->run(function () use ($input, $out): void {
            $schedule = $this->schedules->pause((string) $input->getArgument('name'), AuditLog::CLI_ACTOR);
            $out->emit($this->shapes->schedule($schedule), static function (JsonOutput $out) use ($schedule): void {
                $out->success(\sprintf('Schedule "%s" %s.', $schedule->name(), $schedule->isEnabled() ? 'is active' : 'is paused'));
            });
        });
    }
}
