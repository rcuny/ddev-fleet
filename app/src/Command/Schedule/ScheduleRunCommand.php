<?php

declare(strict_types=1);

namespace App\Command\Schedule;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Job\JobTrigger;
use App\Domain\Schedule\ScheduleOutcome;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Schedules\ScheduleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:schedule:run', description: 'Run a schedule now (also when it is paused)')]
final class ScheduleRunCommand extends Command
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
        $exit = JsonOutput::OK;

        $code = $out->run(function () use ($input, $out, &$exit): void {
            $run = $this->schedules->runNow((string) $input->getArgument('name'), JobTrigger::Cli, AuditLog::CLI_ACTOR);
            // A run that could not start is reported, with the reason, and exits 2 like any refused operation.
            if (ScheduleOutcome::Skipped === $run->outcome() || ScheduleOutcome::Failed === $run->outcome()) {
                $exit = JsonOutput::REFUSED;
            }
            $out->emit($this->shapes->run($run), static function (JsonOutput $out) use ($run): void {
                $out->success(null !== $run->jobId() ? \sprintf('Started job %d.', $run->jobId()) : \sprintf('Not started: %s', $run->detail() ?? $run->outcome()->value));
            });
        });

        return JsonOutput::OK === $code ? $exit : $code;
    }
}
