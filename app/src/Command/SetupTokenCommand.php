<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Host\HostSettingsRepository;
use App\Setup\SetupRefused;
use App\Setup\SetupTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the one-time URL that opens the setup wizard. Minting replaces any earlier token. The URL is the only
 * line on stdout and it is never logged; anything else this command says goes to stderr.
 */
#[AsCommand(
    name: 'fleet:setup:token',
    description: 'Mint a one-time setup token and print the setup URL (valid 24 hours)',
)]
final class SetupTokenCommand extends Command
{
    public function __construct(
        private readonly HostSettingsRepository $settings,
        private readonly SetupTokenService $tokens,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = new SymfonyStyle($input, $output)->getErrorStyle();
        $host = $this->settings->get();
        if (null === $host) {
            $errors->error('The host is not initialised; run fleet:host:init first.');

            return Command::FAILURE;
        }

        try {
            $token = $this->tokens->mint();
        } catch (SetupRefused $e) {
            $errors->error($e->getMessage());

            return Command::FAILURE;
        }

        // plain write: no formatting, no verbosity gate, exactly one line
        $output->writeln(self::url($host->domain(), $token), OutputInterface::OUTPUT_PLAIN | OutputInterface::VERBOSITY_QUIET);

        return Command::SUCCESS;
    }

    public static function url(string $domain, #[\SensitiveParameter] string $token): string
    {
        return \sprintf('https://%s/setup?token=%s', $domain, $token);
    }
}
