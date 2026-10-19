<?php

declare(strict_types=1);

namespace App\Command\Claude;

use App\Domain\Job\JobTrigger;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Workspace\ClaudeTokenApplier;
use App\Fleet\Workspace\ClaudeTokenRotation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rotates the fleet-wide Claude token. The token is never an argument or an option (shell history, process list): a person
 * is asked for it with the input hidden, a script pipes it on standard input. The output never repeats it.
 *
 * Running instances keep the token they started with. `--apply` also restarts all of them (fleet:claude:token:apply does the
 * same later, for one or all); without it nothing is restarted, because a restart ends a running agent.
 */
#[AsCommand(name: 'fleet:claude:token:set', description: 'Replace the fleet-wide Claude token (read from a hidden prompt or standard input)')]
final class ClaudeTokenSetCommand extends Command
{
    private const int MAX_READ = 1024;

    public function __construct(private readonly ClaudeTokenRotation $rotation, private readonly ClaudeTokenApplier $applier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Also restart every running instance, one at a time, so it picks the token up (ends a running agent)');
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

            return Command::SUCCESS;
        }
        if (true === $input->getOption('apply')) {
            try {
                $jobs = $this->applier->apply($result->instances, JobTrigger::Cli, AuditLog::CLI_ACTOR);
            } catch (NotFound|Refused $e) {
                $io->error('The token is stored, but the restart was not queued: '.$e->getMessage());

                return Command::FAILURE;
            }
            $io->writeln(\sprintf('Queued %d restart job%s, one instance at a time.', \count($jobs), 1 === \count($jobs) ? '' : 's'));

            return Command::SUCCESS;
        }
        $io->writeln(\sprintf('Running instances keep their current token until they are restarted (%d):', \count($result->instances)));
        $io->listing($result->instances);
        $io->writeln('Run `fleet claude:token:apply --all` (or --instance=<id>) to restart them with the new token; it ends a running agent.');

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
