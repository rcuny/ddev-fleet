<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Project\ProjectRepository;
use Psr\Log\LoggerInterface;

/**
 * Brings the host's slice units in line with the projects' and templates' CPU allotments: renders slices.json, stages it
 * and has the root helper install it (`apply slices`): unit files and drop-ins, slices started before any container,
 * quotas applied to the running slices. Called after every change that adds, removes or re-limits a project or template.
 *
 * The change that triggered it is already committed, so a failure is a warning, never a rollback: it is logged and
 * kept for the caller (takeWarning()), and the next sync retries. Stale and missing slices are healed by any sync.
 */
final class SlicesApplier
{
    private ?string $warning = null;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly HostSettingsRepository $host,
        private readonly LogicalCpus $cpus,
        private readonly SlicesRenderer $renderer,
        private readonly SystemRenderer $system,
        private readonly Applier $applier,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws \RuntimeException when staging or the helper fails
     */
    public function apply(): void
    {
        try {
            $this->system->stageFile(ApplyTarget::Slices, 'slices.json', $this->document());
            $this->applier->apply(ApplyTarget::Slices);
        } finally {
            $this->system->discardStaging(ApplyTarget::Slices);
        }
    }

    /**
     * The slices.json the helper would be given now (installs nothing).
     */
    public function document(): string
    {
        $reserve = $this->host->get()?->cpuReserveThreads() ?? HostSettings::DEFAULT_CPU_RESERVE_THREADS;
        $allocatable = max(1, $this->cpus->count() - $reserve);

        $projects = [];
        foreach ($this->projects->all() as $project) {
            $templates = [];
            foreach ($this->projects->templateCapacities($project) as $template) {
                $templates[] = ['name' => $template['name'], 'cpuThreads' => $template['cpuThreads']];
            }
            $projects[] = ['name' => $project->name(), 'cpuThreads' => $project->cpuThreads(), 'templates' => $templates];
        }

        return $this->renderer->render($allocatable, $projects);
    }

    /**
     * apply(), but a failure becomes a warning (takeWarning()) instead of an exception.
     */
    public function sync(): void
    {
        try {
            $this->apply();
        } catch (\RuntimeException $e) {
            $this->logger?->warning('The CPU slices were not updated after a change.', ['exception' => $e]);
            $this->warning = 'The change was saved, but the CPU limits were not applied on the host: '.$e->getMessage();
        }
    }

    /**
     * The warning left by the last sync(), once.
     */
    public function takeWarning(): ?string
    {
        $warning = $this->warning;
        $this->warning = null;

        return $warning;
    }
}
