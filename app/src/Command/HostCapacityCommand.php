<?php

declare(strict_types=1);

namespace App\Command;

use App\Fleet\Host\Capacity\HostCapacity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the host's CPU capacity and allotments (FLE-200). `--json` is the contract the ddev-fleet skill consumes
 * (schema 1, additive changes only). Information, not a gate: the exit code is 0 whatever the numbers say (2 only
 * for an unreadable option). --reserve and --max-instances are what-ifs and are never saved.
 */
#[AsCommand(name: 'fleet:host:capacity', description: 'Show the host\'s CPU threads, allotments, live use and slot occupancy')]
final class HostCapacityCommand extends Command
{
    public function __construct(private readonly HostCapacity $capacity)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON')
            ->addOption('reserve', null, InputOption::VALUE_REQUIRED, 'Compute with this many reserved threads instead of the saved value')
            ->addOption('max-instances', null, InputOption::VALUE_REQUIRED, 'Report this instance cap instead of the saved value');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reserve = $this->number($input->getOption('reserve'), 0);
        $max = $this->number($input->getOption('max-instances'), 1);
        if (false === $reserve || false === $max) {
            $output->writeln('<error>--reserve and --max-instances take a whole number (reserve from 0, max-instances from 1).</error>');

            return Command::INVALID;
        }

        $report = $this->capacity->report(null, $reserve, $max);
        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        /* @var array{threads: int, cores: int, ram_mb: int, reserve_threads: int, allocatable_threads: int, committed_threads: int, overcommitted: bool, instances: array{count: int, max: int}, projects: list<array<string, mixed>>} $report */
        $output->writeln(\sprintf('threads %d, cores %d, RAM %d MB; reserve %d, allocatable %d, committed %d%s', $report['threads'], $report['cores'], $report['ram_mb'], $report['reserve_threads'], $report['allocatable_threads'], $report['committed_threads'], $report['overcommitted'] ? ' (OVERCOMMITTED)' : ''));
        $output->writeln(\sprintf('instances %d/%d', $report['instances']['count'], $report['instances']['max']));
        foreach ($report['projects'] as $project) {
            $output->writeln(\sprintf('  %s: %s threads%s, slots %s, busy %s, use %s', $project['name'], $project['effective_threads'], null === $project['cpu_threads'] ? ' (unlimited)' : '', $this->show($project['slots'], 'unlimited'), $project['busy'], $this->show($project['used_cores'], 'n/a')));
            /** @var list<array<string, mixed>> $templates */
            $templates = $project['templates'];
            foreach ($templates as $template) {
                $output->writeln(\sprintf('    %s: %s threads%s, slots %s, busy %s, use %s', $template['name'], $template['effective_threads'], null === $template['cpu_threads'] ? ' (inherited)' : '', $this->show($template['slots'], 'unlimited'), $template['busy'], $this->show($template['used_cores'], 'n/a')));
            }
        }

        return Command::SUCCESS;
    }

    /** @return int|false|null null when not given, false when unreadable or below $min */
    private function number(mixed $value, int $min): int|false|null
    {
        if (null === $value) {
            return null;
        }

        return \is_string($value) && 1 === preg_match('/^[0-9]{1,9}$/D', $value) && (int) $value >= $min ? (int) $value : false;
    }

    private function show(mixed $value, string $none): string
    {
        return null === $value ? $none : (string) (\is_scalar($value) ? $value : '');
    }
}
