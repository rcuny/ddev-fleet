<?php

declare(strict_types=1);

namespace App\Command\Token;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\InvalidApiTokenRequest;
use App\Domain\ApiToken\ApiTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replaces a token with a successor that has the same scopes and restrictions. The old token dies at once, or after
 * --grace seconds (at most one hour). A revoked or expired token cannot be rotated. The new secret is the only line on stdout.
 */
#[AsCommand(name: 'fleet:token:rotate', description: 'Replace an API token with a successor (new secret printed once)')]
final class TokenRotateCommand extends Command
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly ApiTokenIssuer $issuer,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Token id (see token:list)')
            ->addOption('grace', null, InputOption::VALUE_REQUIRED, 'Seconds the old token keeps working (0 to '.ApiTokenIssuer::MAX_GRACE_SECONDS.')', '0')
            ->addOption('expires-in', null, InputOption::VALUE_REQUIRED, 'Lifetime of the successor in days (default depends on the scopes)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON, secret included');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = new SymfonyStyle($input, $output)->getErrorStyle();
        $id = (string) $input->getArgument('id');
        $old = 1 === preg_match('/^\d{1,9}$/D', $id) ? $this->tokens->find((int) $id) : null;
        if (null === $old) {
            $errors->error('No token with that id.');

            return Command::FAILURE;
        }
        $grace = $input->getOption('grace');
        $days = $input->getOption('expires-in');
        if (!\is_string($grace) || 1 !== preg_match('/^\d{1,5}$/D', $grace) || (int) $grace > ApiTokenIssuer::MAX_GRACE_SECONDS) {
            $errors->error('--grace is a number of seconds, 0 to '.ApiTokenIssuer::MAX_GRACE_SECONDS.'.');

            return Command::INVALID;
        }
        if (null !== $days && (!\is_string($days) || 1 !== preg_match('/^[1-9]\d{0,3}$/D', $days))) {
            $errors->error('--expires-in is a whole number of days.');

            return Command::INVALID;
        }

        try {
            $issued = $this->issuer->rotate($old, 'cli', (int) $grace, null === $days ? null : $this->clock->now()->modify('+'.$days.' days'));
        } catch (InvalidApiTokenRequest $e) {
            $errors->error($e->getMessage());

            return Command::FAILURE;
        }

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode(['id' => $issued->token->id(), 'rotated_from' => $old->id(), 'expires_at' => $issued->token->expiresAt()->format(\DATE_ATOM), 'secret' => $issued->secret], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_PLAIN | OutputInterface::VERBOSITY_QUIET);

            return Command::SUCCESS;
        }
        $errors->writeln(\sprintf('Token %d replaces %d. %s The new secret below is shown once.', $issued->token->id(), $old->id(), '0' === $grace ? 'The old token is revoked.' : 'The old token works for '.$grace.' more seconds.'));
        $output->writeln($issued->secret, OutputInterface::OUTPUT_PLAIN | OutputInterface::VERBOSITY_QUIET);

        return Command::SUCCESS;
    }
}
