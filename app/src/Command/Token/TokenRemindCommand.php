<?php

declare(strict_types=1);

namespace App\Command\Token;

use App\Api\Token\ApiTokenReminder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Records the daily expiry reminders (audit `api_token.expiring`) and prints a warning per token that expires soon.
 * Exit code 0 always: a reminder is not a failure. The worker runs the same check once a day.
 */
#[AsCommand(name: 'fleet:token:remind', description: 'Warn about API tokens that expire soon (and write the daily audit reminder)')]
final class TokenRemindCommand extends Command
{
    public function __construct(private readonly ApiTokenReminder $reminder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tokens = $this->reminder->remind();
        if ([] === $tokens) {
            $output->writeln('No token expires soon.');

            return Command::SUCCESS;
        }
        foreach ($tokens as $token) {
            $output->writeln(OutputFormatter::escape(\sprintf('WARNING token #%d "%s" expires %s UTC', $token->id(), $token->name(), $token->expiresAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'))));
        }

        return Command::SUCCESS;
    }
}
