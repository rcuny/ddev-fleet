<?php

declare(strict_types=1);

namespace App\Command\Schedule;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Schedules\ScheduleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:schedule:history', description: 'Show the runs of a schedule, newest first')]
final class ScheduleHistoryCommand extends Command
{
    public function __construct(private readonly ScheduleService $schedules, private readonly ScheduleShapes $shapes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Schedule name');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many runs to show', '50');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::schedule());

        return $out->run(function () use ($input, $out): void {
            $limit = max(1, min(500, (int) $input->getOption('limit')));
            $rows = array_map($this->shapes->run(...), $this->schedules->history((string) $input->getArgument('name'), $limit));
            $out->emit($rows, static fn (JsonOutput $out) => $out->table(
                ['ID', 'OCCURRENCE', 'TRIGGER', 'RESULT', 'JOB', 'DETAIL'],
                array_map(static fn (array $row): array => [$row['id'], $row['occurrence'], $row['trigger'], $row['result'], $row['job'] ?? '-', $row['detail'] ?? ''], $rows),
            ));
        });
    }
}
