<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Domain\Instance\InstanceRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Isolation\ComposeOverrideGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * FLE-298: read-only report of the existing instances whose `.ddev/` files the deploy guard would now refuse. Nothing is
 * changed, stopped or redeployed. Exit code 0 = every instance passes (or is allowed by its project), 1 = at least one would be
 * refused. `--json` is schema 1.
 */
#[AsCommand(name: 'fleet:instance:audit-overrides', description: 'List instances whose compose overrides the deploy guard would refuse (read-only)')]
final class AuditOverridesCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly ComposeOverrideGuard $guard,
        private readonly FleetPaths $paths,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->instances->all() as $instance) {
            $dir = $this->paths->instanceDir($instance->id());
            if (!is_dir($dir.'/.ddev')) {
                continue;
            }
            $violations = $this->guard->scan($dir, [$this->paths->agentSocketDir($instance->id())]);
            if ([] === $violations) {
                continue;
            }
            $rows[] = [
                'instance' => $instance->id(),
                'project' => $instance->project()->name(),
                'allowed' => $instance->project()->allowPrivilegedOverrides(),
                'violations' => array_map(static fn ($v): array => ['file' => $v->file, 'key' => $v->key, 'reason' => $v->reason], $violations),
            ];
        }
        $refused = array_values(array_filter($rows, static fn (array $r): bool => !$r['allowed']));

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode(['schema' => 1, 'instances' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        } elseif ([] === $rows) {
            $output->writeln('No instance has overrides the deploy guard would refuse.');
        } else {
            foreach ($rows as $row) {
                $output->writeln(\sprintf('%s (project %s)%s', $row['instance'], $row['project'], $row['allowed'] ? ' - allowed by the project' : ' - WOULD BE REFUSED'));
                foreach ($row['violations'] as $v) {
                    $output->writeln('  '.OutputFormatter::escape(\sprintf('%s: %s (%s)', $v['file'], $v['key'], $v['reason'])));
                }
            }
        }

        return [] === $refused ? Command::SUCCESS : Command::FAILURE;
    }
}
