<?php

declare(strict_types=1);

namespace App\Command\Job;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Fleet\Projects\ValidationFailed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:job:list', description: 'List the jobs, newest first')]
final class ListCommand extends Command
{
    public function __construct(private readonly JobRepository $jobs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'queued, running, succeeded, failed or interrupted')
            ->addOption('trigger', null, InputOption::VALUE_REQUIRED, 'ui, cli, webhook or schedule')
            ->addOption('instance', null, InputOption::VALUE_REQUIRED, 'Only the jobs of this instance')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many jobs at most', '50')
        ;
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, new FieldErrors([]));

        return $out->run(function () use ($input, $out): void {
            $errors = [];
            $status = $input->getOption('status');
            $trigger = $input->getOption('trigger');
            $limit = $input->getOption('limit');
            $statusValue = \is_string($status) ? JobStatus::tryFrom($status) : null;
            $triggerValue = \is_string($trigger) ? JobTrigger::tryFrom($trigger) : null;
            if (null !== $status && null === $statusValue) {
                $errors['status'] = 'Unknown status.';
            }
            if (null !== $trigger && null === $triggerValue) {
                $errors['trigger'] = 'Unknown trigger.';
            }
            if (!\is_string($limit) || 1 !== preg_match('/^[0-9]{1,4}$/D', $limit) || (int) $limit < 1) {
                $errors['limit'] = 'The limit must be a number from 1 to 9999.';
            }
            if ([] !== $errors) {
                throw new ValidationFailed($errors);
            }

            $instance = $input->getOption('instance');
            $found = $this->jobs->search(
                ['status' => $statusValue, 'trigger' => $triggerValue, 'instance' => \is_string($instance) ? $instance : null],
                1,
                (int) $limit,
            )['rows'];
            $rows = array_map(self::row(...), $found);

            $out->emit(['jobs' => $rows], static function (JsonOutput $out) use ($rows): void {
                if ([] === $rows) {
                    $out->success('No jobs.');

                    return;
                }
                $out->table(
                    ['Job', 'Type', 'Instance', 'Status', 'Trigger', 'By', 'Created'],
                    array_map(static fn (array $r): array => [$r['id'], $r['type'], $r['instance'] ?? '-', $r['status'], $r['trigger'], $r['requestedBy'], $r['createdAt']], $rows),
                );
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Job $job): array
    {
        return [
            'id' => $job->id(),
            'type' => $job->type(),
            'instance' => $job->instanceId(),
            'project' => $job->projectName(),
            'status' => $job->status()->value,
            'trigger' => $job->trigger()->value,
            'requestedBy' => $job->requestedBy(),
            'batch' => $job->batchId(),
            'retryOf' => $job->retryOf(),
            'exitCode' => $job->exitCode(),
            'error' => $job->error(),
            'createdAt' => $job->createdAt()->format(\DATE_ATOM),
            'startedAt' => $job->startedAt()?->format(\DATE_ATOM),
            'finishedAt' => $job->finishedAt()?->format(\DATE_ATOM),
        ];
    }
}
