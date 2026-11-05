<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\TemplateRepository;
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
 */
final readonly class HostCapacity
{
    public const int SCHEMA = 1;

    public function __construct(
        private HostHardwareProbe $hardware,
        private HostSettingsRepository $settings,
        private ProjectRepository $projects,
        private TemplateRepository $templates,
        private InstanceRepository $instances,
        private UsageSampler $sampler,
        private SlotLocks $locks,
        private ClockInterface $clock,
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
            foreach ($this->templates->forProject($project) as $template) {
                $tUnit = $usage[SliceName::template($project->name(), $template->name())] ?? null;
                $templates[] = [
                    'name' => $template->name(),
                    'cpu_threads' => $template->cpuThreads(),
                    'effective_threads' => min($template->cpuThreads() ?? $effective, $effective),
                    'slots' => $template->slots(),
                    'busy' => $this->locks->busy($project->name(), $template->name()),
                    'used_cores' => $tUnit['used_cores'] ?? null,
                ];
            }

            $projects[] = [
                'name' => $project->name(),
                'cpu_threads' => $project->cpuThreads(),
                'effective_threads' => $effective,
                'used_cores' => $unit['used_cores'] ?? null,
                'throttled' => $unit['throttled'] ?? null,
                'slots' => $project->slots(),
                'busy' => $this->locks->busy($project->name(), null),
                'templates' => $templates,
            ];
        }

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
            'instances' => ['count' => \count($this->instances->ids()), 'max' => $maxInstances],
            'projects' => $projects,
        ];
    }
}
