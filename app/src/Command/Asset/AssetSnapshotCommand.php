<?php

declare(strict_types=1);

namespace App\Command\Asset;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Job\JobTrigger;
use App\Fleet\Assets\SnapshotService;
use App\Fleet\Audit\AuditLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:asset:snapshot', description: 'Dump a running instance\'s database into its project\'s assets (dumps/)')]
final class AssetSnapshotCommand extends Command
{
    public function __construct(private readonly SnapshotService $snapshots)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('instance-id', InputArgument::REQUIRED, 'Instance id, for example blog--main');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::asset());

        return $out->run(function () use ($input, $out): void {
            $job = $this->snapshots->request((string) $input->getArgument('instance-id'), JobTrigger::Cli, AuditLog::CLI_ACTOR);

            $out->emit(
                ['job' => $job->id(), 'instance' => $job->instanceId(), 'project' => $job->projectName(), 'status' => $job->status()->value],
                static function (JsonOutput $out) use ($job): void {
                    $out->success(\sprintf('Snapshot of %s queued as job %d. The dump appears in the project\'s dumps/ folder when it is done.', $job->instanceId(), $job->id()));
                },
            );
        });
    }
}
