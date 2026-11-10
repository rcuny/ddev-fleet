<?php

declare(strict_types=1);

namespace App\Command;

use App\Fleet\Host\Drift\DriftCheck;
use App\Fleet\Host\Drift\DriftCheckFailed;
use App\Fleet\Host\Drift\DriftReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only drift report (FLE-261): `sudo fleet host:drift [--json]` runs the installed release's playbook with
 * --check --diff through the root helper and reports, per role, what is unchanged, what would change (with the diff,
 * secrets masked) and what could not be checked. Nothing on the host changes. The report is also kept as the last one
 * that /admin/host shows. Exit code: 0 no drift, 1 drift found, 2 a task failed, 3 no report (nothing was checked).
 * `--json` is schema 1, additive changes only.
 */
#[AsCommand(name: 'fleet:host:drift', description: 'Check the host against the installed release without changing anything')]
final class HostDriftCommand extends Command
{
    public const int EXIT_DRIFT = 1;
    public const int EXIT_FAILED = 2;
    public const int EXIT_NO_REPORT = 3;

    public function __construct(private readonly DriftCheck $check)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = true === $input->getOption('json');
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if (!$json) {
            $errors->writeln('Checking the host against the installed release (read-only, this takes a few minutes)...');
        }
        try {
            $report = $this->check->run();
        } catch (DriftCheckFailed $e) {
            $errors->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return self::EXIT_NO_REPORT;
        }

        if ($json) {
            $output->writeln(json_encode($report->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        } else {
            $this->print($report, $output);
        }

        return $report->hasFailures() ? self::EXIT_FAILED : ($report->hasDrift() ? self::EXIT_DRIFT : Command::SUCCESS);
    }

    private function print(DriftReport $report, OutputInterface $output): void
    {
        $w = static fn (string $line) => $output->writeln(OutputFormatter::escape($line));
        $w(\sprintf('Release %s, checked %s UTC', '' === $report->ref ? 'unknown' : $report->ref, $report->checkedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')));
        foreach ($report->roles as $role) {
            $w(\sprintf('%-14s %s', $role['role'], strtoupper($role['status'])));
            foreach ($role['drift'] as $message) {
                $w('    DRIFT: '.$message);
            }
            foreach ($role['changed'] as $entry) {
                $w('    would change: '.$entry['task']);
                foreach ('' === $entry['diff'] ? [] : explode("\n", $entry['diff']) as $line) {
                    $w('        '.$line);
                }
            }
            foreach ($role['failed'] as $entry) {
                $w('    FAILED: '.$entry['task'].': '.$entry['msg']);
            }
        }
        $w($report->hasFailures() ? 'Some tasks could not be checked (see FAILED above).' : ($report->hasDrift() ? 'Drift found.' : 'No drift.'));
        $w('Note: '.DriftReport::LIMITS);
    }
}
