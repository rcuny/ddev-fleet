<?php

declare(strict_types=1);

namespace App\Command\Host;

use App\Domain\Host\IsolationMode;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Isolation\IsolationConverger;
use App\Fleet\Isolation\IsolationStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * FLE-299: `fleet host:isolation:enable --mode=report|enforce [--yes]`. Never run by the installer, an upgrade or a deploy.
 * `enforce` always needs --yes and first prints every recorded flow no link covers (what it would cut).
 */
#[AsCommand(name: 'fleet:host:isolation:enable', description: 'Turn instance network isolation on in report mode (records flows, cuts nothing) or enforce mode (drops what no link allows)')]
final class HostIsolationEnableCommand extends Command
{
    public function __construct(private readonly IsolationConverger $converger, private readonly IsolationStatus $status, private readonly ?\App\Fleet\PortMirror\RouterRefresh $router = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'report or enforce', 'report')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirm enforce (required)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = IsolationMode::tryFrom((string) $input->getOption('mode'));
        if (null === $mode || IsolationMode::Off === $mode) {
            $output->writeln('<error>--mode takes report or enforce (use host:isolation:disable to turn it off).</error>');

            return Command::INVALID;
        }
        if (IsolationMode::Enforce === $mode) {
            $doc = $this->status->build();
            /** @var list<array<string, mixed>> $flows */
            $flows = $doc['flows'];
            $uncovered = array_filter($flows, static fn (array $f): bool => true !== $f['covered']);
            foreach ($uncovered as $f) {
                $output->writeln(\sprintf('would cut: %s -> %s:%d%s', $f['source'], $f['target'], $f['port'], null === $f['suggestion'] ? '' : ' (allow with: '.$f['suggestion'].')'));
            }
            if (true !== $input->getOption('yes')) {
                $output->writeln('<error>Refused: enforce needs --yes. Read the flows above first; run report mode for a few days before this.</error>');
                $output->writeln('Roll back at any time: fleet host:isolation:disable');

                return Command::FAILURE;
            }
        }
        try {
            $pass = $this->converger->changeMode($mode, AuditLog::CLI_ACTOR);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        if (!$pass->ok()) {
            $output->writeln('<error>The mode is saved but the first pass failed: '.$pass->error.'</error>');

            return Command::FAILURE;
        }
        $output->writeln('Isolation mode: '.$mode->value.($pass->applied ? ' (ruleset installed)' : ''));
        if (IsolationMode::Enforce === $mode && null !== $this->router) {
            // FLE-326: the router's own copies must hold the current mirror files (the PROXY trust of the :443 mirror) before enforce is real.
            $router = $this->router->run();
            foreach ($router['lines'] as $line) {
                $output->writeln($line);
            }
            foreach ($router['problems'] as $problem) {
                $output->writeln('<error>router: '.$problem.'</error>');
            }
            if ([] !== $router['problems']) {
                return Command::FAILURE;
            }
        }
        $output->writeln('Roll back at any time: fleet host:isolation:disable');

        return Command::SUCCESS;
    }
}
