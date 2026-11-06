<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Host\Capacity\SlotLocks;
use App\Fleet\System\LogicalCpus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The capacity variables of an instance's `web_environment` (capacity spec section 4, owner decision 2026-11-05: slots
 * reach the CI scripts by env injection only, baked at deploy/restart):
 *
 * - `FLEET_PROJECT_SLOTS`, `FLEET_TEMPLATE_SLOTS`: the slot caps, empty = unlimited;
 * - `FLEET_TEMPLATE_CPU_THREADS`: the template's effective allotment (template limit capped by the project's, which is
 *   capped by what the host can allocate);
 * - `FLEET_SLOT_DIR`, `FLEET_PROJECT_SLOT_DIR`: the lock directories of the template pool and of the project pool
 *   (`/srv/fleet/slots/<project>/<template>/` and `/srv/fleet/slots/<project>/_project/`), created here.
 */
final readonly class InstanceCapacityEnvironment
{
    public function __construct(
        private TemplateRepository $templates,
        private HostSettingsRepository $host,
        private LogicalCpus $cpus,
        #[Autowire('%fleet.slots_dir%')]
        private string $slotsDir,
    ) {
    }

    /**
     * Creates the two slot directories and returns the `NAME=value` lines.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when a slot directory cannot be created
     */
    public function variables(Instance $instance, Project $project): array
    {
        $template = $this->templates->find($project, $instance->templateName());

        $reserve = $this->host->get()?->cpuReserveThreads() ?? HostSettings::DEFAULT_CPU_RESERVE_THREADS;
        $allocatable = max(1, $this->cpus->count() - $reserve);
        $effectiveProject = min($project->cpuThreads() ?? $allocatable, $allocatable);
        $effectiveTemplate = min($template?->cpuThreads() ?? $effectiveProject, $effectiveProject);

        $templateDir = $this->slotsDir.'/'.$project->name().'/'.$instance->templateName();
        $projectDir = $this->slotsDir.'/'.$project->name().'/'.SlotLocks::PROJECT_POOL;
        foreach ([$templateDir, $projectDir] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create the slot directory '.$dir.'.');
            }
        }

        return [
            'FLEET_PROJECT_SLOTS='.($project->slots() ?? ''),
            'FLEET_TEMPLATE_SLOTS='.($template?->slots() ?? ''),
            'FLEET_TEMPLATE_CPU_THREADS='.$effectiveTemplate,
            'FLEET_SLOT_DIR='.$templateDir.'/',
            'FLEET_PROJECT_SLOT_DIR='.$projectDir.'/',
        ];
    }
}
