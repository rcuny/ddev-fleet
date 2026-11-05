<?php

declare(strict_types=1);

namespace App\Command;

use App\Fleet\System\SlicesApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installs the CPU slices of the capacity allotments through the root helper (`apply slices`). Limit changes do this
 * on their own; run it once after an upgrade to a release that has slices, or to repair a host (missing or stale slices).
 */
#[AsCommand(
    name: 'fleet:host:slices',
    description: 'Install the CPU slices for the projects and templates on this host (--print: show them only)',
)]
final class HostSlicesCommand extends Command
{
    public function __construct(private readonly SlicesApplier $slices)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('print', null, InputOption::VALUE_NONE, 'Print the slice list (JSON) and install nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if ($input->getOption('print')) {
                $output->writeln($this->slices->document());

                return Command::SUCCESS;
            }
            $this->slices->apply();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success('The CPU slices are installed.');

        return Command::SUCCESS;
    }
}
