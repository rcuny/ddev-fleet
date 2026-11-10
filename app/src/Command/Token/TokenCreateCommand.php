<?php

declare(strict_types=1);

namespace App\Command\Token;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\ApiTokenPolicy;
use App\Api\Token\InvalidApiTokenRequest;
use App\Domain\ApiToken\ApiScope;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Issues an API service token (FLE-275). The secret is the only line on stdout (or the `secret` field with --json) and is
 * never shown again; everything else goes to stderr. The actor in the audit log is `cli`.
 */
#[AsCommand(
    name: 'fleet:token:create',
    description: 'Create an API token (the secret is printed once)',
    help: <<<'HELP'
        Creates a service token for the HTTP API. Scopes: read, deploy, lifecycle, lifecycle:destroy, terminal:read,
        terminal:write. Projects are names, or "*" for a read-only token. Expiry is required: --expires-in=DAYS, or the
        default for the scopes (90 days read/deploy, 30 lifecycle, 1 terminal). terminal:write needs --ip.

          sudo fleet token:create orchestrator --scope=read --scope=lifecycle --project=blog --ip=203.0.113.0/24
        HELP,
)]
final class TokenCreateCommand extends Command
{
    public function __construct(private readonly ApiTokenIssuer $issuer, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Token name: lower-case letters, digits, ".", "_", "-" (max 32)')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'What the token is for', '')
            ->addOption('scope', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A scope (repeat for several)')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A project name, or * (read-only tokens)')
            ->addOption('instance-glob', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Instance pattern for terminal scopes')
            ->addOption('ip', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Allowed address or CIDR range (repeat)')
            ->addOption('expires-in', null, InputOption::VALUE_REQUIRED, 'Lifetime in days (default depends on the scopes)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON, secret included');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = new SymfonyStyle($input, $output)->getErrorStyle();
        $scopes = [];
        foreach ((array) $input->getOption('scope') as $value) {
            $scope = ApiScope::tryFrom((string) $value);
            if (null === $scope) {
                $errors->error(\sprintf('Unknown scope "%s". Scopes: %s.', $value, implode(', ', array_map(static fn (ApiScope $s): string => $s->value, ApiScope::cases()))));

                return Command::INVALID;
            }
            $scopes[] = $scope;
        }
        $days = $input->getOption('expires-in');
        if (null !== $days && (!\is_string($days) || 1 !== preg_match('/^[1-9]\d{0,3}$/D', $days))) {
            $errors->error('--expires-in is a whole number of days.');

            return Command::INVALID;
        }
        $now = $this->clock->now();
        $lifetime = null === $days ? ApiTokenPolicy::defaultLifetimeDays($scopes) : (int) $days;

        try {
            $issued = $this->issuer->issue(
                (string) $input->getArgument('name'),
                (string) $input->getOption('description'),
                $scopes,
                $this->strings($input, 'project'),
                $this->strings($input, 'instance-glob'),
                $this->strings($input, 'ip'),
                $now->modify('+'.$lifetime.' days'),
                'cli',
            );
        } catch (InvalidApiTokenRequest $e) {
            $errors->error($e->getMessage());

            return Command::FAILURE;
        }

        $token = $issued->token;
        if (true === $input->getOption('json')) {
            $output->writeln(json_encode(['id' => $token->id(), 'name' => $token->name(), 'expires_at' => $token->expiresAt()->format(\DATE_ATOM), 'secret' => $issued->secret], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_PLAIN | OutputInterface::VERBOSITY_QUIET);

            return Command::SUCCESS;
        }
        $errors->writeln(\sprintf('Token %d "%s" created, expires %s UTC. The secret below is shown once; store it as a secret.', $token->id(), $token->name(), $token->expiresAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')));
        $output->writeln($issued->secret, OutputInterface::OUTPUT_PLAIN | OutputInterface::VERBOSITY_QUIET);

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function strings(InputInterface $input, string $option): array
    {
        return array_values(array_map(static fn (mixed $v): string => (string) $v, (array) $input->getOption($option)));
    }
}
