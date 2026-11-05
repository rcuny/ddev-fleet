<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Instances\SliceMembership;
use Psr\Clock\ClockInterface;

/**
 * The capacity of this host and what is allotted on it (FLE-200, spec 2 and 5). Builds the `fleet:host:capacity --json`
 * document (schema 1: additive changes only): detected threads/cores/RAM, the reserve, allocatable threads, the
 * effective limit of every project and template, what is committed, live use and slot `busy`.
 *
 *   allocatable = threads - reserve
 *   effective project  = min(project.cpuThreads ?? allocatable, allocatable)
 *   effective template = min(template.cpuThreads ?? effective project, effective project)
 *   committed          = the sum of the effective project limits (unlimited counts as allocatable)
 *
 * `docker_networks` (additive, FLE-219) = {used, max}: bridge networks now and what the running daemon's address pools
 * hold (null = unknown); `instances.max` is the smaller of the configured cap and that room.
 *
 * `busy` of a pool (FLE-212) = the larger of (a) the lock files held right now (SlotLocks) and (b) 1 when the pool's
 * slice used at least ACTIVE_CORES threads over the last sample, so an agent that holds no slot lock still counts. The
 * template pool reads its own slice; the `_project` pool reads the project slice minus its template slices (a cgroup
 * slice includes its children). `unreadable_locks` (additive) counts lock files/directories that could not be read.
 *
 * `instances.not_under_slice` (additive) lists the instances without the cgroup override (SliceMembership).
 *
 * `used_cores` is null = unknown: no sample yet, a sample older than UsageSampler::STALE_SECONDS (the status watcher is
 * down), or the slice does not exist (nothing ran in it). After a watcher restart the first reading needs two samples,
 * about 10 to 20 seconds. Zero is a real reading: the slice exists and was idle.
 */
final readonly class HostCapacity
{
    public const int SCHEMA = 1;
    /** A slice using at least this many threads over the last sample counts as one busy slot. */
    public const float ACTIVE_CORES = 0.2;

    public function __construct(
        private HostHardwareProbe $hardware,
        private HostSettingsRepository $settings,
        private ProjectRepository $projects,
        private TemplateRepository $templates,
        private InstanceRepository $instances,
        private UsageSampler $sampler,
        private DockerNetworkProbe $networks,
        private SlotLocks $locks,
        private ClockInterface $clock,
        private ?SliceMembership $membership = null,
    ) {
    }

    /**
     * @param int|null $reserve      overrides the stored reserve (what-if)
     * @param int|null $maxInstances overrides the stored instance cap (what-if)
     *
     * @return array<string, mixed>
     */
    public function report(?string $host = null, ?int $reserve = null, ?int $maxInstances = null): array
    {
        $hardware = $this->hardware->read();
        $settings = $this->settings->get();
        $reserve ??= $settings?->cpuReserveThreads() ?? HostSettings::DEFAULT_CPU_RESERVE_THREADS;
        $maxInstances ??= $settings?->maxInstances() ?? HostSettings::DEFAULT_MAX_INSTANCES;
        $allocatable = max(0, $hardware->threads - $reserve);
        $usage = $this->sampler->usage($this->clock->now());

        $committed = 0;
        $projects = [];
        foreach ($this->projects->all() as $project) {
            $effective = min($project->cpuThreads() ?? $allocatable, $allocatable);
            $committed += $effective;
            $unit = $usage[SliceName::project($project->name())] ?? null;

            $templates = [];
            $templatesUsed = 0.0;
            $unreadable = 0;
            foreach ($this->templates->forProject($project) as $template) {
                $tUnit = $usage[SliceName::template($project->name(), $template->name())] ?? null;
                $tLocks = $this->locks->inspect($project->name(), $template->name());
                $unreadable += $tLocks['unreadable'];
                $templatesUsed += $tUnit['used_cores'] ?? 0.0;
                $templates[] = [
                    'name' => $template->name(),
                    'cpu_threads' => $template->cpuThreads(),
                    'effective_threads' => min($template->cpuThreads() ?? $effective, $effective),
                    'slots' => $template->slots(),
                    'busy' => max($tLocks['busy'], ($tUnit['used_cores'] ?? 0.0) >= self::ACTIVE_CORES ? 1 : 0),
                    'used_cores' => $tUnit['used_cores'] ?? null,
                ];
            }
            $pLocks = $this->locks->inspect($project->name(), null);
            $unreadable += $pLocks['unreadable'];
            $ownUse = ($unit['used_cores'] ?? 0.0) - $templatesUsed;

            $projects[] = [
                'name' => $project->name(),
                'cpu_threads' => $project->cpuThreads(),
                'effective_threads' => $effective,
                'used_cores' => $unit['used_cores'] ?? null,
                'throttled' => $unit['throttled'] ?? null,
                'slots' => $project->slots(),
                'busy' => max($pLocks['busy'], $ownUse >= self::ACTIVE_CORES ? 1 : 0),
                'unreadable_locks' => $unreadable,
                'templates' => $templates,
            ];
        }

        // Every instance holds one Docker bridge network. Room for instances = the free networks plus the ones the
        // instances already hold; the cap is the smaller of that and the configured one (FLE-219).
        $count = \count($this->instances->ids());
        $docker = $this->networks->read();
        $instanceMax = null === $docker ? $maxInstances : min($maxInstances, max(0, $docker['max'] - $docker['used']) + $count);

        return [
            'schema' => self::SCHEMA,
            'host' => $host ?? $settings?->domain() ?? '',
            'threads' => $hardware->threads,
            'cores' => $hardware->cores,
            'ram_mb' => $hardware->ramMb,
            'reserve_threads' => $reserve,
            'allocatable_threads' => $allocatable,
            'committed_threads' => $committed,
            'overcommitted' => $committed > $allocatable,
            'instances' => ['count' => $count, 'max' => $instanceMax, 'not_under_slice' => $this->membership?->outside() ?? []],
            'docker_networks' => $docker,
            'projects' => $projects,
        ];
    }
}
