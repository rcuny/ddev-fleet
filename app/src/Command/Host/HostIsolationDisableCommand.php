<?php

declare(strict_types=1);

namespace App\Command\Host;

use App\Domain\Host\IsolationMode;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Isolation\IsolationConverger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * FLE-299: `fleet host:isolation:disable` deletes the table at once (nothing is restarted). The link networks stay unless --remove-links.
 */
#[AsCommand(name: 'fleet:host:isolation:disable', description: 'Turn instance network isolation off: the nftables table is deleted at once, nothing restarts')]
final class HostIsolationDisableCommand extends Command
{
    public function __construct(private readonly IsolationConverger $converger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('remove-links', null, InputOption::VALUE_NONE, 'Also remove the fleet-link-* Docker networks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->converger->changeMode(IsolationMode::Off, AuditLog::CLI_ACTOR, true === $input->getOption('remove-links'));
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            $output->writeln('By hand, as root: nft delete table inet fleet_isolation');

            return Command::FAILURE;
        }
        $output->writeln('Isolation mode: off (the table is deleted).');

        return Command::SUCCESS;
    }
}
