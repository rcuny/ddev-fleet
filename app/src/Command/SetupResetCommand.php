<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Fleet\System\GateMode;
use App\Fleet\System\SystemRenderer;
use App\Setup\SetupTokenService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only way to open setup again after it completed. Host shell only, and it asks for confirmation on a terminal
 * (or needs --yes, which is as deliberate: there is no web route that can run this).
 */
#[AsCommand(
    name: 'fleet:setup:reset',
    description: 'Open the setup wizard again: mint a new token and put the setup gate back',
    help: <<<'HELP'
        Marks setup as not complete, mints a new one-time token and switches the Caddy gate to the setup gate, so until the
        wizard finishes again only the wizard, its assets and /health are reachable. Existing users, keys and secrets are
        kept; the wizard updates them.

        Without a terminal it needs <info>--yes</info>.
        HELP,
)]
final class SetupResetCommand extends Command
{
    public function __construct(
        private readonly HostSettingsRepository $settings,
        private readonly SetupTokenService $tokens,
        private readonly SystemRenderer $system,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        // Tests turn this off; in production a confirmation is only accepted from a real terminal, never from a pipe.
        private readonly bool $requireRealTerminal = true,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $io->getErrorStyle();

        $host = $this->settings->get();
        if (null === $host) {
            $errors->error('The host is not initialised; run fleet:host:init first.');

            return Command::FAILURE;
        }

        if (!$input->getOption('yes')) {
            if (!$input->isInteractive() || ($this->requireRealTerminal && !stream_isatty(\STDIN))) {
                $errors->error('This reopens the setup wizard and needs a terminal to confirm; run it interactively or pass --yes.');

                return Command::FAILURE;
            }
            if (!$errors->confirm('Reopen the setup wizard? Until it finishes again the app is reachable only through /setup.', false)) {
                $errors->writeln('Nothing was changed.');

                return Command::FAILURE;
            }
        }

        $previousCompletedAt = $host->setupCompletedAt();
        $previousHash = $host->setupTokenHash();
        $previousExpiry = $host->setupTokenExpiresAt();

        $host->reopenSetup();
        $this->settings->save($host);
        try {
            $token = $this->tokens->mint();
            $this->system->renderGate($host, GateMode::Setup);
        } catch (\Throwable $e) {
            // Without the setup gate the wizard could not be reached: undo, so the host is as it was.
            $this->restore($host, $previousCompletedAt, $previousHash, $previousExpiry);
            $errors->error('Setup was not reopened: '.$e->getMessage());

            return Command::FAILURE;
        }

        // Audit line: who and when, never the token.
        $this->logger->warning('Setup reopened from the host shell.', [
            'user' => self::osUser(),
            'at' => $this->clock->now()->format(\DATE_ATOM),
            'domain' => $host->domain(),
        ]);

        $output->writeln(SetupTokenCommand::url($host->domain(), $token), OutputInterface::OUTPUT_PLAIN | OutputInterface::VERBOSITY_QUIET);

        return Command::SUCCESS;
    }

    private static function osUser(): string
    {
        if (!\function_exists('posix_geteuid') || !\function_exists('posix_getpwuid')) {
            return 'unknown';
        }
        $info = posix_getpwuid(posix_geteuid());

        return false === $info ? 'uid '.posix_geteuid() : $info['name'];
    }

    private function restore(HostSettings $host, ?\DateTimeImmutable $completedAt, ?string $hash, ?\DateTimeImmutable $expiry): void
    {
        $host->clearSetupToken();
        if (null !== $hash && null !== $expiry) {
            $host->storeSetupToken($hash, $expiry);
        }
        if (null !== $completedAt) {
            $host->markSetupComplete($completedAt);
        }
        $this->settings->save($host);
    }
}
