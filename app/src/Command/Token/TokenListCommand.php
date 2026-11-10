<?php

declare(strict_types=1);

namespace App\Command\Token;

use App\Api\Token\ApiTokenWatch;
use App\Domain\ApiToken\ApiToken;
use App\Domain\ApiToken\ApiTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists API tokens (never the secret), with the tokens that expire within 14 days or were not used for 60 days flagged.
 * `--json` is schema 1, additive changes only.
 */
#[AsCommand(name: 'fleet:token:list', description: 'List API tokens (active ones; --all adds revoked and expired)')]
final class TokenListCommand extends Command
{
    public function __construct(private readonly ApiTokenRepository $tokens, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Include revoked and expired tokens')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now();
        $all = $this->tokens->all();
        $shown = true === $input->getOption('all') ? $all : array_values(array_filter($all, static fn (ApiToken $t): bool => $t->isUsable($now)));
        $expiring = array_map(static fn (ApiToken $t): ?int => $t->id(), ApiTokenWatch::expiring($all, $now));
        $unused = array_map(static fn (ApiToken $t): ?int => $t->id(), ApiTokenWatch::unused($all, $now));

        $rows = array_map(static fn (ApiToken $t): array => [
            'id' => $t->id(),
            'name' => $t->name(),
            'description' => $t->description(),
            'prefix_hint' => $t->prefixHint(),
            'state' => ApiTokenWatch::state($t, $now),
            'scopes' => $t->scopes(),
            'projects' => $t->projects(),
            'instance_globs' => $t->instanceGlobs(),
            'ip_allow' => $t->ipAllow(),
            'created_by' => $t->createdBy(),
            'created_at' => $t->createdAt()->format(\DATE_ATOM),
            'expires_at' => $t->expiresAt()->format(\DATE_ATOM),
            'last_used_at' => $t->lastUsedAt()?->format(\DATE_ATOM),
            'last_used_ip' => $t->lastUsedIp(),
            'revoked_at' => $t->revokedAt()?->format(\DATE_ATOM),
            'rotated_from' => $t->rotatedFrom(),
            'expiring' => \in_array($t->id(), $expiring, true),
            'unused' => \in_array($t->id(), $unused, true),
        ], $shown);

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode(['schema' => 1, 'tokens' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        if ([] === $rows) {
            $output->writeln('No tokens.');

            return Command::SUCCESS;
        }
        foreach ($rows as $row) {
            $flags = array_filter([$row['expiring'] ? 'EXPIRING' : null, $row['unused'] ? 'UNUSED' : null]);
            $output->writeln(OutputFormatter::escape(\sprintf(
                '#%d %s (%s…) %s scopes=%s projects=%s expires %s last used %s%s',
                $row['id'],
                $row['name'],
                $row['prefix_hint'],
                $row['state'],
                implode(',', $row['scopes']),
                implode(',', $row['projects']),
                substr($row['expires_at'], 0, 10),
                null === $row['last_used_at'] ? 'never' : substr($row['last_used_at'], 0, 16).' from '.($row['last_used_ip'] ?? '?'),
                [] === $flags ? '' : '  ['.implode(' ', $flags).']',
            )));
        }

        return Command::SUCCESS;
    }
}
