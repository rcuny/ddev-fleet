<?php

declare(strict_types=1);

namespace App\Command\Schedule;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Schedules\ScheduleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:schedule:list', description: 'List the schedules')]
final class ScheduleListCommand extends Command
{
    public function __construct(private readonly ScheduleService $schedules, private readonly ScheduleShapes $shapes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::schedule());

        return $out->run(function () use ($out): void {
            $rows = array_map($this->shapes->schedule(...), $this->schedules->list());
            $out->emit($rows, static fn (JsonOutput $out) => $out->table(
                ['NAME', 'CRON', 'TIMEZONE', 'ACTION', 'INSTANCE', 'STATE', 'NEXT FIRE', 'LAST RESULT'],
                array_map(static fn (array $row): array => [
                    $row['name'], $row['cron'], $row['timezone'], $row['action'], $row['instance'] ?? '-',
                    $row['enabled'] ? 'active' : 'paused', $row['next_fire'] ?? '-', $row['last_result'] ?? '-',
                ], $rows),
            ));
        });
    }
}
