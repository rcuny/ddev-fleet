<?php

declare(strict_types=1);

namespace App\Command\Upgrade;

use App\Fleet\Upgrade\UpgradeHistory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `fleet upgrade history`: the upgrades run on this host, newest first (the root-owned history file; read-only). */
#[AsCommand(
    name: 'fleet:upgrade:history',
    description: 'Show the upgrades run on this host, newest first',
)]
final class UpgradeHistoryCommand extends Command
{
    public function __construct(private readonly UpgradeHistory $history)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many lines to show', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the entries as a JSON array')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, min(500, (int) $input->getOption('limit')));
        $entries = $this->history->entries($limit);

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(array_map(static fn ($e): array => $e->toArray(), $entries), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }
        if ([] === $entries) {
            $output->writeln('No upgrade has been run on this host yet.');

            return Command::SUCCESS;
        }
        foreach ($entries as $e) {
            $output->writeln(\sprintf(
                '%s  %-9s %-10s %s -> %s  by %s%s',
                $e->at->format('Y-m-d H:i:s'),
                $e->event,
                $e->result,
                $e->from,
                $e->to,
                $e->actor,
                null !== $e->backup ? '  backup '.$e->backup : '',
            ), OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
