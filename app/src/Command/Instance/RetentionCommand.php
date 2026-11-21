<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Command\Support\CliRequester;
use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\InstanceRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:instance:retention', description: 'Destroy the old `ci-` instances of the projects that set a CI retention (--dry-run lists them)')]
final class RetentionCommand extends Command
{
    public function __construct(private readonly InstanceRetention $retention)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Only this project (default: every project with a retention)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be destroyed and change nothing')
        ;
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::instance());

        return $out->run(function () use ($input, $out): void {
            $project = $input->getOption('project');
            $project = \is_string($project) ? $project : null;
            if (true === $input->getOption('dry-run')) {
                $rows = array_map(static fn (array $c): array => [
                    'id' => $c['instance']->id(),
                    'project' => $c['instance']->project()->name(),
                    'reference_at' => $c['since']->format(\DATE_ATOM),
                    'retention_days' => $c['days'],
                ], $this->retention->candidates($project));
                $out->emit(['dry_run' => true, 'would_destroy' => $rows], static function (JsonOutput $out) use ($rows): void {
                    if ([] === $rows) {
                        $out->success('Nothing to destroy.');

                        return;
                    }
                    $out->table(['Id', 'Last deploy', 'Retention days'], array_map(static fn (array $r): array => [$r['id'], $r['reference_at'], $r['retention_days']], $rows));
                });

                return;
            }

            $result = $this->retention->run($project, CliRequester::name(), JobTrigger::Cli);
            $out->emit(['dry_run' => false] + $result, static function (JsonOutput $out) use ($result): void {
                $out->success(\sprintf('%d instance(s) queued for destroy, %d skipped.', \count($result['destroyed']), \count($result['skipped'])));
                foreach ($result['skipped'] as $row) {
                    $out->success(\sprintf('skipped %s: %s', $row['id'], $row['reason']));
                }
            });
        });
    }
}
