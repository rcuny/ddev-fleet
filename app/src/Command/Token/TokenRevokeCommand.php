<?php

declare(strict_types=1);

namespace App\Command\Token;

use App\Api\Token\ApiTokenIssuer;
use App\Domain\ApiToken\ApiTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Revokes an API token: immediate, irreversible, audited. Revoking a revoked token is a no-op. */
#[AsCommand(name: 'fleet:token:revoke', description: 'Revoke an API token (immediate, irreversible)')]
final class TokenRevokeCommand extends Command
{
    public function __construct(private readonly ApiTokenRepository $tokens, private readonly ApiTokenIssuer $issuer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Token id (see token:list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');
        $token = 1 === preg_match('/^\d{1,9}$/D', $id) ? $this->tokens->find((int) $id) : null;
        if (null === $token) {
            $io->getErrorStyle()->error('No token with that id.');

            return Command::FAILURE;
        }
        $already = $token->isRevoked();
        $this->issuer->revoke($token, 'cli');
        $io->writeln($already ? 'Already revoked.' : \sprintf('Token %d "%s" revoked.', $token->id(), $token->name()));

        return Command::SUCCESS;
    }
}
