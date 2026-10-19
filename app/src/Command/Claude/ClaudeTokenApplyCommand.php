<?php

declare(strict_types=1);

namespace App\Command\Claude;

use App\Domain\Job\JobTrigger;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Workspace\ClaudeTokenApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Restarts running instances so they pick up the stored Claude token. Never run by itself: the restart ends whatever runs
 * in the containers, so a person asks for it, for one instance or all.
 */
#[AsCommand(name: 'fleet:claude:token:apply', description: 'Restart running instances so they pick up the stored Claude token')]
final class ClaudeTokenApplyCommand extends Command
{
    public function __construct(private readonly ClaudeTokenApplier $applier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('instance', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restart this instance (repeatable)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Restart every running instance')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the queued jobs as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $ids */
        $ids = array_values(array_filter((array) $input->getOption('instance'), is_string(...)));
        $all = true === $input->getOption('all');
        if ($all === ([] !== $ids)) {
            $io->error('Choose --instance=<id> (repeatable) or --all. The restart ends what runs in the containers, so it is never implied.');

            return Command::INVALID;
        }

        try {
            $jobs = $this->applier->apply($all ? null : $ids, JobTrigger::Cli, AuditLog::CLI_ACTOR);
        } catch (NotFound|Refused $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (true === $input->getOption('json')) {
            $output->writeln((string) json_encode(array_map(static fn ($job): array => ['job' => $job->id(), 'instance' => $job->instanceId()], $jobs), \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        if ([] === $jobs) {
            $io->writeln('No instance is running.');

            return Command::SUCCESS;
        }
        $io->success(\sprintf('Queued %d restart job%s, one instance at a time.', \count($jobs), 1 === \count($jobs) ? '' : 's'));
        $io->listing(array_map(static fn ($job): string => \sprintf('job %d: %s', $job->id(), $job->instanceId()), $jobs));

        return Command::SUCCESS;
    }
}
