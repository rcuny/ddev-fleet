<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\Ddev\Ddev;
use App\Fleet\Ddev\DdevProject;
use App\Fleet\Ddev\DdevUnavailable;
use App\Fleet\Host\Capacity\UsageSampler;
use App\Fleet\Jobs\JobFeed;
use App\Fleet\Jobs\JobPruner;
use App\Fleet\Jobs\JobReaper;
use App\Fleet\Ssh\InstanceSshAgent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps the instance rows true to the host. One tick does what is due: `ddev list` every 10 s (running or stopped),
 * `docker stats` every 30 s (memory), the job reaper every tick and the retention prune once a day. It only moves
 * instances that are running, stopped or unknown: deploying, destroying and failed belong to a job or to a person.
 * Every InstanceSshAgent::REFRESH_SECONDS it also revives and re-keys the ssh-agents of opted-in instances (FLE-167).
 * Every USAGE_SECONDS it samples the CPU use of the capacity slices from cgroup `cpu.stat` (FLE-200).
 * When ddev does not answer, those instances become `unknown`, never `stopped`.
 */
final class InstanceWatcher
{
    public const int LIST_SECONDS = 10;
    public const int RAM_SECONDS = 30;
    public const int PRUNE_SECONDS = 86400;
    public const int USAGE_SECONDS = 10;

    private ?\DateTimeImmutable $listedAt = null;
    private ?\DateTimeImmutable $measuredAt = null;
    private ?\DateTimeImmutable $prunedAt = null;
    private ?\DateTimeImmutable $agentsAt = null;
    private ?\DateTimeImmutable $sampledAt = null;

    /** @var array<string, int> */
    private array $ram = [];

    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly Ddev $ddev,
        private readonly JobReaper $reaper,
        private readonly JobPruner $pruner,
        private readonly JobFeed $feed,
        #[Autowire('%fleet.job_retention_days%')]
        private readonly int $retentionDays = 90,
        private readonly ?InstanceSshAgent $agent = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?UsageSampler $usage = null,
    ) {
    }

    /**
     * @return int the number of instances whose state or memory changed
     */
    public function tick(\DateTimeImmutable $now): int
    {
        $this->reaper->reap();

        if (null === $this->measuredAt || self::due($this->measuredAt, $now, self::RAM_SECONDS)) {
            $this->ram = $this->ddev->ramUsage();
            $this->measuredAt = $now;
        }

        $changed = 0;
        if (null === $this->listedAt || self::due($this->listedAt, $now, self::LIST_SECONDS)) {
            $changed = $this->observe($now);
            $this->listedAt = $now;
        }

        if (null === $this->prunedAt || self::due($this->prunedAt, $now, self::PRUNE_SECONDS)) {
            $this->pruner->prune($this->retentionDays);
            $this->prunedAt = $now;
        }

        if (null !== $this->agent && (null === $this->agentsAt || self::due($this->agentsAt, $now, InstanceSshAgent::REFRESH_SECONDS))) {
            $this->superviseAgents();
            $this->agentsAt = $now;
        }

        if (null !== $this->usage && (null === $this->sampledAt || self::due($this->sampledAt, $now, self::USAGE_SECONDS))) {
            // FLE-200: the CPU use of the capacity slices; a failure here must not stop the state updates.
            try {
                $this->usage->sample($now);
            } catch (\Throwable $e) {
                $this->logger->warning('The capacity usage could not be sampled.', ['exception' => $e]);
            }
            $this->sampledAt = $now;
        }

        return $changed;
    }

    /**
     * FLE-167: for every running instance of an opted-in project, start() revives a dead agent and renews the key
     * lifetime (`ssh-add -t`), so a long-lived instance never loses push after KEY_LIFETIME. A failure is only logged.
     */
    private function superviseAgents(): void
    {
        if (null === $this->agent) {
            return;
        }
        foreach ($this->instances->all() as $instance) {
            if (InstanceState::Running !== $instance->state() || !$instance->project()->containerSsh()) {
                continue;
            }
            if (!$this->agent->hasKeyFor($instance)) {
                continue;
            }
            try {
                $this->agent->startFor($instance);
            } catch (\RuntimeException $e) {
                $this->logger->warning('The ssh-agent of an instance could not be refreshed.', ['instance' => $instance->id(), 'exception' => $e]);
            }
        }
    }

    private function observe(\DateTimeImmutable $now): int
    {
        $projects = null;
        try {
            $projects = $this->ddev->list();
        } catch (DdevUnavailable) {
            // The rows are marked unknown below.
        }

        $touched = [];
        $changed = [];
        foreach ($this->instances->all() as $instance) {
            if (!\in_array($instance->state(), [InstanceState::Running, InstanceState::Stopped, InstanceState::Unknown], true)) {
                continue;
            }

            $state = null === $projects ? InstanceState::Unknown : self::stateOf($projects[$instance->id()] ?? null);
            $ram = InstanceState::Running === $state ? ($this->ram[$instance->id()] ?? $instance->ramMib()) : null;

            if ($state !== $instance->state() || $ram !== $instance->ramMib()) {
                $changed[] = $instance;
            }
            $instance->observe($state, $ram, $now);
            $touched[] = $instance;
        }

        if ([] !== $touched) {
            $this->instances->saveAll($touched);
        }
        foreach ($changed as $instance) {
            $this->feed->instanceChanged($instance->id());
        }

        return \count($changed);
    }

    private static function stateOf(?DdevProject $project): InstanceState
    {
        return match (true) {
            null === $project => InstanceState::Unknown,
            $project->isRunning() => InstanceState::Running,
            \in_array($project->status, ['stopped', 'paused'], true) => InstanceState::Stopped,
            default => InstanceState::Unknown,
        };
    }

    private static function due(\DateTimeImmutable $last, \DateTimeImmutable $now, int $seconds): bool
    {
        return $now->getTimestamp() - $last->getTimestamp() >= $seconds;
    }
}
