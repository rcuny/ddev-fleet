<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobTrigger;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceDbIsolationWriter;
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
 * FLE-297 repair path: instances deployed before the database isolation override exist have their db container on the
 * shared `ddev_default` network. Without options this lists them (read-only). With `--apply` it queues one `restart` job
 * per RUNNING exposed instance (the job re-renders the override, then `ddev restart`). Opt-in: the restart ends whatever
 * runs in the containers, so nothing is restarted automatically. Stopped instances get the override at their next start
 * through a restart or redeploy.
 */
#[AsCommand(name: 'fleet:instance:isolate', description: 'List instances whose db is still on the shared ddev network (--apply: restart the running ones)')]
final class InstanceIsolateCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly FleetPaths $paths,
        private readonly ClaudeTokenApplier $applier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Queue a restart for every running exposed instance (ends what runs in their containers)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation (--apply)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $exposed = [];
        $running = [];
        foreach ($this->instances->all() as $instance) {
            $dir = $this->paths->instanceDir($instance->id());
            if (!is_dir($dir.'/.ddev') || InstanceDbIsolationWriter::isIsolated($dir)) {
                continue;
            }
            $exposed[] = $instance->id();
            if (InstanceState::Running === $instance->state()) {
                $running[] = $instance->id();
            }
        }
        sort($exposed);
        sort($running);

        if ([] === $exposed) {
            $io->success('Every instance has the database isolation override.');

            return Command::SUCCESS;
        }
        $io->writeln(\sprintf('%d instance(s) without the database isolation override (%d running):', \count($exposed), \count($running)));
        $io->listing($exposed);
        if (true !== $input->getOption('apply')) {
            $io->note('Nothing changed. Run with --apply to restart the running ones; stopped ones get the override at their next restart or redeploy.');

            return Command::SUCCESS;
        }
        if ([] === $running) {
            return Command::SUCCESS;
        }
        if (true !== $input->getOption('yes') && (!$input->isInteractive() || !$io->confirm(\sprintf('Restart %d running instance(s)? This ends whatever runs in their containers.', \count($running)), false))) {
            $io->error('Not restarted: pass --yes to confirm.');

            return Command::FAILURE;
        }
        try {
            $jobs = $this->applier->apply($running, JobTrigger::Cli, AuditLog::CLI_ACTOR);
        } catch (NotFound|Refused $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Queued %d restart job(s), one instance at a time.', \count($jobs)));

        return Command::SUCCESS;
    }
}
