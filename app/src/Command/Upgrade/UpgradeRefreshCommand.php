<?php

declare(strict_types=1);

namespace App\Command\Upgrade;

use App\Fleet\Upgrade\ReleaseCatalog;
use App\Fleet\Upgrade\UpgradeRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refreshes the release catalog (the cache the Upgrade page and the footer read). Run daily by
 * fleet-upgrade-check.timer. Silent on success. A source that cannot be reached is not a failure of the host: the
 * stale catalog stays, the reason is stored and shown on stderr, and the exit code is 0.
 */
#[AsCommand(
    name: 'fleet:upgrade:refresh',
    description: 'Look up the published releases and refresh the cached catalog',
)]
final class UpgradeRefreshCommand extends Command
{
    public function __construct(private readonly ReleaseCatalog $catalog, private readonly UpgradeRefresher $refresher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('if-stale', null, InputOption::VALUE_NONE, 'Do nothing when the cached catalog is younger than 6 hours');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption('if-stale') && $this->catalog->isFresh()) {
            return Command::SUCCESS;
        }

        $state = $this->refresher->refresh();
        if (null !== $state->error) {
            new SymfonyStyle($input, $output)->getErrorStyle()->warning('Release check failed, keeping the previous list: '.$state->error);
        }

        return Command::SUCCESS;
    }
}
