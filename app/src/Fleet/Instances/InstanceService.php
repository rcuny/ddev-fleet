<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Instance\LabelTaken;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\TemplateRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Jobs\JobService;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\Rule\BranchName;
use App\Fleet\Projects\ValidationFailed;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * Turns a request (deploy, start, stop, redeploy, destroy, retry) into instance rows and queued jobs. It never runs a
 * process: the rows and their jobs are written in one flush, then each job is handed to the queue.
 *
 * Job params (read by the job types): deploy `{branch, template, label, requestedLabel, force}`; start and stop
 * `{timeout, retryPortConflict}`; redeploy adds `{template, force}`; destroy `{}`.
 */
final class InstanceService
{
    public const int MAX_COUNT = 20;
    private const int LABEL_ATTEMPTS = 3;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly TemplateRepository $templates,
        private readonly InstanceRepository $instances,
        private readonly JobRepository $jobs,
        private readonly JobService $jobService,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly FleetPaths $paths,
        private readonly DiskHeadroom $headroom,
        private readonly InstanceHostnames $hostnames,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<Job> one per instance, in label order
     *
     * @throws ValidationFailed
     * @throws NotFound
     * @throws Refused
     */
    public function requestDeploy(DeployInput $in, JobTrigger $trigger, string $by): array
    {
        $project = $this->projects->findByName($in->project) ?? throw NotFound::project($in->project);
        [$branch, $template, $base] = $this->deployChoices($project, $in);
        if ($in->count > 1 && !$in->skipDiskCheck) {
            $this->headroom->ensure();
        }

        for ($attempt = 1;; ++$attempt) {
            try {
                return $this->createDeployJobs($in, $trigger, $by, $branch, $template, $base);
            } catch (LabelTaken) {
                if ($attempt >= self::LABEL_ATTEMPTS) {
                    throw new Refused('Another request took the same label at the same time: try again.');
                }
                // The failed flush closed the entity manager; a fresh one sees the winner's row.
                $this->registry->resetManager();
            }
        }
    }

    /**
     * @param string $op one of start, stop, redeploy, destroy
     *
     * @return list<Job> one per instance, ordered by instance id (or as the ids were given)
     *
     * @throws ValidationFailed
     * @throws NotFound
     * @throws Refused          when any selected instance has a queued or running job: nothing is created then
     */
    public function request(string $op, Selector $selector, OperationOptions $options, JobTrigger $trigger, string $by): array
    {
        if (!\in_array($op, ['start', 'stop', 'redeploy', 'destroy'], true)) {
            throw new \InvalidArgumentException('Unknown operation.');
        }

        $instances = $this->resolve($selector);
        $template = 'redeploy' === $op ? $this->redeployTemplate($instances, $options) : null;
        $this->refuseIfBusy($instances);

        $bulk = \count($instances) > 1;
        $sequential = $bulk && ($options->sequential || \in_array($op, ['destroy', 'redeploy'], true));
        $batchId = $bulk ? bin2hex(random_bytes(8)) : null;
        $now = $this->clock->now();

        $jobs = [];
        foreach ($instances as $instance) {
            $params = match ($op) {
                'start', 'stop' => ['timeout' => $options->timeout, 'retryPortConflict' => $options->retryPortConflict],
                'redeploy' => ['template' => $template ?? $instance->templateName(), 'force' => $options->force],
                default => [],
            };
            if ('redeploy' === $op || 'destroy' === $op) {
                $instance->changeState('redeploy' === $op ? InstanceState::Deploying : InstanceState::Destroying, $now);
            }
            $jobs[] = new Job($op, $instance->id(), $instance->project()->name(), $trigger, $by, $params, $batchId, $sequential, null, $now);
        }
        foreach ($jobs as $job) {
            $this->em->persist($job);
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw self::busyAtTheSameTime();
        }
        foreach ($jobs as $job) {
            $this->jobService->enqueue($job);
        }

        return $jobs;
    }

    /**
     * Repeats a failed or interrupted job (a failed deploy becomes a redeploy).
     *
     * @throws NotFound
     * @throws Refused
     */
    public function retry(int $jobId, JobTrigger $trigger, string $by): Job
    {
        $failed = $this->jobs->find($jobId) ?? throw NotFound::job($jobId);
        $this->jobService->assertRetryable($failed);
        $id = $failed->instanceId() ?? throw new Refused('This job belongs to no instance and cannot be retried.');
        $instance = $this->instances->find($id) ?? throw NotFound::instance($id);
        $this->refuseIfBusy([$instance]);

        // A start or stop has no transitional state: the instance stays as it was until the job's outcome is known.
        $state = match ($failed->type()) {
            'destroy' => InstanceState::Destroying,
            'deploy', 'redeploy' => InstanceState::Deploying,
            default => null,
        };
        if (null !== $state) {
            $instance->changeState($state, $this->clock->now());
        }

        // The state change is flushed together with the new job (JobService::retry persists it), so a request that
        // loses the race for the instance changes nothing.
        try {
            return $this->jobService->retry($failed, $by, $trigger);
        } catch (UniqueConstraintViolationException) {
            throw self::busyAtTheSameTime();
        }
    }

    private static function busyAtTheSameTime(): Refused
    {
        return new Refused('Busy: another request just queued a job for this instance. Nothing was started.');
    }

    /**
     * @return array{string, string, string} branch, template name, label base
     */
    private function deployChoices(Project $project, DeployInput $in): array
    {
        $errors = [];

        $branch = $in->branch ?? $project->defaultBranch();
        if (null === $branch) {
            $errors['branch'] = 'Choose a branch: the project has no default branch.';
        } elseif (null !== ($error = BranchName::error($branch))) {
            $errors['branch'] = $error;
        }

        $template = $in->template ?? $project->defaultTemplate();
        if (null === $template) {
            $errors['template'] = 'Choose a template: the project has no default template.';
        } elseif (null === $this->templates->find($project, $template)) {
            $errors['template'] = 'This project has no template with that name.';
        }

        if ($in->count < 1 || $in->count > self::MAX_COUNT) {
            $errors['count'] = \sprintf('The count must be between 1 and %d.', self::MAX_COUNT);
        }

        $base = '';
        if (!isset($errors['branch'])) {
            try {
                $base = Labels::slug($in->label ?? (string) $branch);
            } catch (ValidationFailed $e) {
                $errors += $e->errors;
            }
        }
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        return [(string) $branch, (string) $template, $base];
    }

    /**
     * @return list<Job>
     *
     * @throws LabelTaken
     */
    private function createDeployJobs(DeployInput $in, JobTrigger $trigger, string $by, string $branch, string $template, string $base): array
    {
        $project = $this->projects->findByName($in->project) ?? throw NotFound::project($in->project);
        $taken = $this->takenIds();
        $labels = 1 === $in->count
            ? [InstanceNaming::allocateFree($taken, $project->name(), $base)]
            : InstanceNaming::allocateBatch($taken, $project->name(), $base, $in->count);

        $batch = $in->count > 1;
        $batchId = $batch ? bin2hex(random_bytes(8)) : null;
        $sequential = $batch && !$in->parallel;
        $now = $this->clock->now();

        $instances = [];
        $jobs = [];
        foreach ($labels as $label) {
            $id = InstanceNaming::compose($project->name(), $label);
            $this->hostnames->aliasesIfSetUp($project, $id);
            $instances[] = new Instance($project, $label, $template, $branch, $by, $now);
            $jobs[] = new Job('deploy', $id, $project->name(), $trigger, $by, [
                'branch' => $branch,
                'template' => $template,
                'label' => $label,
                'requestedLabel' => $base,
                'force' => $in->force,
            ], $batchId, $sequential, null, $now);
        }

        foreach ($jobs as $job) {
            $this->em->persist($job);
        }
        $this->instances->createMany($instances);
        foreach ($jobs as $job) {
            $this->jobService->enqueue($job);
        }

        return $jobs;
    }

    /**
     * Ids in the database plus checkouts on disk. A directory without `.git` (a half-cloned stub) occupies nothing.
     *
     * @return list<string>
     */
    private function takenIds(): array
    {
        $taken = $this->instances->ids();
        foreach (glob($this->paths->instancesDir().'/*', \GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (InstanceNaming::isInstanceId($name) && file_exists($dir.'/.git')) {
                $taken[] = $name;
            }
        }

        return array_values(array_unique($taken));
    }

    /**
     * @return list<Instance>
     */
    private function resolve(Selector $selector): array
    {
        $bySelector = $selector->all || null !== $selector->project || null !== $selector->state;
        if ([] !== $selector->ids && $bySelector) {
            throw new ValidationFailed(['selector' => 'Name instances, or select by --all, project or state, not both.']);
        }
        if ($selector->all && (null !== $selector->project || null !== $selector->state)) {
            throw new ValidationFailed(['selector' => '--all cannot be combined with a project or a state.']);
        }
        if ([] === $selector->ids && !$bySelector) {
            throw new ValidationFailed(['selector' => 'Name at least one instance, or select with --all, a project or a state.']);
        }

        if ([] !== $selector->ids) {
            $found = [];
            foreach (array_unique($selector->ids) as $id) {
                $instance = InstanceNaming::isInstanceId($id) ? $this->instances->find($id) : null;
                $found[] = $instance ?? throw NotFound::instance($id);
            }

            return $found;
        }

        $state = null;
        if (null !== $selector->state) {
            $state = InstanceState::tryFrom($selector->state) ?? throw new ValidationFailed(['state' => 'Unknown state.']);
        }
        if (null !== $selector->project) {
            $project = $this->projects->findByName($selector->project) ?? throw NotFound::project($selector->project);
            $found = $this->instances->byProject($project);
        } else {
            $found = $this->instances->all();
        }
        if (null !== $state) {
            $found = array_values(array_filter($found, static fn (Instance $i): bool => $i->state() === $state));
        }
        if ([] === $found) {
            throw new ValidationFailed(['selector' => 'No instance matches.']);
        }

        return $found;
    }

    /**
     * @param list<Instance> $instances
     */
    private function redeployTemplate(array $instances, OperationOptions $options): ?string
    {
        if (null === $options->template) {
            return null;
        }
        foreach ($instances as $instance) {
            if (null === $this->templates->find($instance->project(), $options->template)) {
                throw new ValidationFailed(['template' => 'The project of '.$instance->id().' has no template with that name.']);
            }
        }

        return $options->template;
    }

    /**
     * @param list<Instance> $instances
     */
    private function refuseIfBusy(array $instances): void
    {
        $busy = $this->jobs->activeInstanceIds(array_map(static fn (Instance $i): string => $i->id(), $instances));
        if ([] !== $busy) {
            throw new Refused(\sprintf('Busy: %s %s a job queued or running. Nothing was started.', implode(', ', \array_slice($busy, 0, 5)).(\count($busy) > 5 ? ' and more' : ''), 1 === \count($busy) ? 'has' : 'have'));
        }
    }
}
