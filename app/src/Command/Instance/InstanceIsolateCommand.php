<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobTrigger;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceDbIsolationWriter;
use App\Fleet\Isolation\ComposeOverrideGuard;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Workspace\ClaudeTokenApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
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
 *
 * FLE-309: instance ids or project names narrow the selection. Instances whose `.ddev/` files the deploy guard refuses (and
 * whose project does not allow privileged overrides) are skipped and reported, because their restart would fail the pre-start
 * gate after `ddev stop`, leaving them down. `--force-refused` queues them anyway, only after an interactive confirmation.
 */
#[AsCommand(name: 'fleet:instance:isolate', description: 'List instances whose db is still on the shared ddev network (--apply: restart the running ones)')]
final class InstanceIsolateCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly FleetPaths $paths,
        private readonly ClaudeTokenApplier $applier,
        private readonly ComposeOverrideGuard $guard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('selectors', InputArgument::IS_ARRAY, 'Instance ids or project names to consider (default: every instance)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Queue a restart for every running exposed instance (ends what runs in their containers)')
            ->addOption('force-refused', null, InputOption::VALUE_NONE, 'Also restart instances the deploy guard refuses (they stay down unless the guard passes; always asks to confirm)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation (--apply)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $selectors = array_values(array_unique(array_map('strval', (array) $input->getArgument('selectors'))));
        $all = $this->instances->all();
        foreach ($selectors as $selector) {
            $hit = false;
            foreach ($all as $instance) {
                if ($instance->id() === $selector || $instance->project()->name() === $selector) {
                    $hit = true;
                }
            }
            if (!$hit) {
                $io->error(\sprintf('No instance or project named "%s". Nothing was changed.', $selector));

                return Command::FAILURE;
            }
        }
        $exposed = [];
        $running = [];
        $refused = [];
        foreach ($all as $instance) {
            if ([] !== $selectors && !\in_array($instance->id(), $selectors, true) && !\in_array($instance->project()->name(), $selectors, true)) {
                continue;
            }
            $dir = $this->paths->instanceDir($instance->id());
            if (!is_dir($dir.'/.ddev') || InstanceDbIsolationWriter::isIsolated($dir)) {
                continue;
            }
            $exposed[] = $instance->id();
            if (InstanceState::Running !== $instance->state()) {
                continue;
            }
            $violations = $instance->project()->allowPrivilegedOverrides() ? [] : $this->guard->scan($dir, [$this->paths->agentSocketDir($instance->id())]);
            if ([] !== $violations) {
                $refused[$instance->id()] = array_map(static fn ($v): string => $v->message(), $violations);
                continue;
            }
            $running[] = $instance->id();
        }
        sort($exposed);
        sort($running);
        ksort($refused);
        $forceRefused = true === $input->getOption('force-refused');

        if ([] === $exposed) {
            $io->success('Every instance has the database isolation override.');

            return Command::SUCCESS;
        }
        $io->writeln(\sprintf('%d instance(s) without the database isolation override (%d running):', \count($exposed), \count($running)));
        $io->listing($exposed);
        foreach ($refused as $id => $messages) {
            $io->writeln(\sprintf('<comment>%s: the deploy guard refuses its overrides, so a restart would leave it stopped:</comment> %s', $id, OutputFormatter::escape(implode('; ', \array_slice($messages, 0, 3)))));
        }
        if (true !== $input->getOption('apply')) {
            $io->note('Nothing changed. Run with --apply to restart the running ones; stopped ones get the override at their next restart or redeploy.');

            return Command::SUCCESS;
        }
        if ($forceRefused && [] !== $refused) {
            if (!$input->isInteractive() || !$io->confirm(\sprintf('%d instance(s) are refused by the deploy guard (%s). Restarting them stops them and they will NOT come back unless the files are fixed. Restart them anyway?', \count($refused), implode(', ', array_keys($refused))), false)) {
                $io->error('Not restarted: --force-refused needs an interactive confirmation (--yes does not replace it).');

                return Command::FAILURE;
            }
            $running = array_merge($running, array_keys($refused));
            sort($running);
        } elseif ([] !== $refused) {
            $io->warning(\sprintf('Skipped %d instance(s) the deploy guard refuses: %s. Fix their .ddev files, allow privileged overrides for the project, or use --force-refused.', \count($refused), implode(', ', array_keys($refused))));
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
