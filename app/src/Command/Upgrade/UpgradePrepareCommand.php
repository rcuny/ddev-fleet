<?php

declare(strict_types=1);

namespace App\Command\Upgrade;

use App\Fleet\Upgrade\RunState;
use App\Fleet\Upgrade\UpgradeRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal: the plan phase of `sudo fleet upgrade`. The report for the operator goes to stderr; stdout carries only
 * `KEY=value` lines for the root helper (STATE, FROM, TO, SHA, ACTIONS), every value limited to a safe character set.
 * Exit codes: 0 ready / up to date, 1 failed, 2 usage, 3 refused. Changes nothing.
 */
#[AsCommand(
    name: 'fleet:upgrade:prepare',
    description: 'Internal: plan and pre-flight for `sudo fleet upgrade`',
    hidden: true,
)]
final class UpgradePrepareCommand extends Command
{
    public function __construct(private readonly UpgradeRunner $runner, private readonly AssessmentPrinter $printer = new AssessmentPrinter())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Upgrade to this release (vX.Y.Z) instead of the latest')
            ->addOption('force', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Check id to waive (overridable Blocks only)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = $input->getOption('to');
        $force = array_values(array_filter((array) $input->getOption('force'), 'is_string'));
        $prep = $this->runner->prepare(\is_string($to) && '' !== $to ? $to : null, $force);
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if (null !== $prep->assessment) {
            $this->printer->print($err, $prep->assessment);
        }
        foreach ($prep->reasons as $reason) {
            $err->writeln('upgrade: '.preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $reason), OutputInterface::OUTPUT_RAW);
        }

        $output->writeln('STATE='.$prep->state->value, OutputInterface::OUTPUT_RAW);
        $output->writeln('FROM='.$prep->from, OutputInterface::OUTPUT_RAW);
        if (RunState::Ready === $prep->state && null !== $prep->target) {
            $output->writeln('TO='.$prep->target->tag(), OutputInterface::OUTPUT_RAW);
            $output->writeln('SHA='.$prep->target->sha, OutputInterface::OUTPUT_RAW);
            $output->writeln('ACTIONS='.\count($prep->actions), OutputInterface::OUTPUT_RAW);
        }

        return $prep->exitCode();
    }
}
