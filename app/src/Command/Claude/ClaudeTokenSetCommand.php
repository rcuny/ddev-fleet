<?php

declare(strict_types=1);

namespace App\Command\Claude;

use App\Fleet\Audit\AuditLog;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Workspace\ClaudeTokenRotation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rotates the fleet-wide Claude token. The token is never an argument or an option (shell history, process list): a person
 * is asked for it with the input hidden, a script pipes it on standard input. The output never repeats it.
 */
#[AsCommand(name: 'fleet:claude:token:set', description: 'Replace the fleet-wide Claude token (read from a hidden prompt or standard input)')]
final class ClaudeTokenSetCommand extends Command
{
    private const int MAX_READ = 1024;

    public function __construct(private readonly ClaudeTokenRotation $rotation)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->rotation->rotate($this->token($input, $output), AuditLog::CLI_ACTOR);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (SecretConflict) {
            $io->error('The token was changed at the same moment. Run the command again.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Claude token stored (%s UTC).', $result->rotatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')));
        if ([] === $result->instances) {
            $io->writeln('No instance is running.');
        } else {
            $io->writeln(\sprintf('These running instances will pick it up when their configuration is next written (%d):', \count($result->instances)));
            $io->listing($result->instances);
        }

        return Command::SUCCESS;
    }

    private function token(InputInterface $input, OutputInterface $output): string
    {
        if ($input->isInteractive()) {
            $answer = new SymfonyStyle($input, $output)->askHidden('Claude token (input is hidden)');

            return \is_string($answer) ? $answer : '';
        }
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $content = stream_get_contents($stream ?? \STDIN, self::MAX_READ);

        return false === $content ? '' : $content;
    }
}
