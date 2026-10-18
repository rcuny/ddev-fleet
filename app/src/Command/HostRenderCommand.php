<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Host\HostSettingsRepository;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\GateMode;
use App\Fleet\System\SystemRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders the Caddy and Authelia config into the staging area for the root helper, and the Caddy gate for the
 * current state of setup. The installer runs it before the first `apply`; an operator runs it after changing the host
 * settings.
 */
#[AsCommand(
    name: 'fleet:host:render',
    description: 'Render the Caddy/Authelia config for the root helper and the Caddy gate',
    help: <<<'HELP'
        Stages the rendered config for <info>apply caddy</info> and <info>apply authelia</info> (the root helper removes what it
        installs) and writes the Caddy gate: <info>--gate=auto</info> is the setup gate until setup is complete, then the
        Authelia gate.

        <info>--apply</info> installs the staged files itself through the helper (sudo) and removes them afterwards.
        <info>--gate=setup</info> after setup completed needs <info>--force</info>.
        <info>--no-reload</info> only writes the gate file; the installer needs it because Caddy is not installed yet.
        HELP,
)]
final class HostRenderCommand extends Command
{
    public function __construct(
        private readonly HostSettingsRepository $settings,
        private readonly SystemRenderer $system,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('gate', null, InputOption::VALUE_REQUIRED, 'auto, setup or authelia', 'auto')
            ->addOption('no-reload', null, InputOption::VALUE_NONE, 'Write the gate file but do not validate or reload Caddy')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow --gate=setup after setup is complete (reopens the wizard\'s gate; prefer fleet:setup:reset)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Install the staged config through the root helper (authelia, then caddy) and remove it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $io->getErrorStyle();

        $gate = $input->getOption('gate');
        $forcedGate = match ($gate) {
            'auto' => null,
            'setup' => GateMode::Setup,
            'authelia' => GateMode::Authelia,
            default => false,
        };
        if (false === $forcedGate) {
            $errors->error('--gate must be auto, setup or authelia.');

            return Command::FAILURE;
        }
        $host = $this->settings->get();
        if (null === $host) {
            $errors->error('The host is not initialised; run fleet:host:init first.');

            return Command::FAILURE;
        }

        if (GateMode::Setup === $forcedGate && $host->isSetupComplete()) {
            if (!$input->getOption('force')) {
                $errors->error('Setup is complete: the setup gate would make /setup reachable without signing in. Use fleet:setup:reset to reopen setup, or pass --force.');

                return Command::FAILURE;
            }
            $errors->warning('Setup is complete, yet the setup gate is being written (--force). The app answers /setup with 404 while setup is complete, but every other page redirects there until the gate is switched back.');
        }

        try {
            if ($input->getOption('apply')) {
                foreach ([ApplyTarget::Authelia, ApplyTarget::Caddy] as $target) {
                    $this->system->stageAndApply($target, $host);
                    $io->writeln(\sprintf('Applied: %s.', $target->value));
                }
            } else {
                $this->system->renderStaging($host);
                $io->writeln('Staged: caddy, authelia.');
            }
            $mode = $this->system->renderGate($host, $forcedGate, !$input->getOption('no-reload'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $errors->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(\sprintf('Gate: %s.', $mode->value));

        return Command::SUCCESS;
    }
}
