<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Ddev\Ddev;
use App\Fleet\Host\Capacity\SliceName;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Ssh\AtomicFile;
use App\Fleet\System\SlicesAvailability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Puts every container of an instance under its template's CPU slice (capacity spec section 3) with
 * `.ddev/docker-compose.fleet-cgroup.yaml`, a compose override that sets `cgroup_parent` on EVERY service. Compose
 * merges per service, so a service missing from the override (an add-on, a profile service such as xhgui) would run in
 * /system.slice with no limit. The list comes from `ddev debug compose-config`, which works on a stopped project and
 * includes add-on and profile services, so the file is written after the add-on files are in place and before
 * `ddev start` (FLE-198). The file carries no `#ddev-generated` marker, so DDEV never overwrites it.
 *
 * Only on a host whose root helper knows the `slices` target (SlicesAvailability): otherwise nothing is written, a stale
 * file is removed, and the instance deploys unconstrained exactly as before.
 *
 * Docker and systemd never fail on a missing slice, they create it without a limit, so check() reads the slice's
 * `cpu.max` after the start and describes a limit that did not arrive.
 */
final readonly class InstanceCgroupWriter
{
    public const string RELATIVE_PATH = '.ddev/docker-compose.fleet-cgroup.yaml';

    public function __construct(
        private Ddev $ddev,
        private TemplateRepository $templates,
        private SlicesAvailability $availability,
        #[Autowire('%fleet.cgroup_root%')]
        private string $cgroupRoot,
    ) {
    }

    /**
     * @return string|null the path written, relative to $dir; null when the host cannot constrain instances
     *
     * @throws \RuntimeException when the services cannot be listed or the file cannot be written
     */
    public function write(Instance $instance, Project $project, string $dir, JobLog $log): ?string
    {
        $file = $dir.'/'.self::RELATIVE_PATH;
        if (!$this->availability->isAvailable()) {
            if (is_file($file)) {
                @unlink($file);
                $log->line('removed '.self::RELATIVE_PATH.': this host has no "slices" helper target');
            }

            return null;
        }

        $slice = SliceName::template($project->name(), $instance->templateName());
        $services = [];
        foreach ($this->ddev->composeServices($dir) as $service) {
            $services[$service] = ['cgroup_parent' => $slice];
        }
        AtomicFile::write($file, Yaml::dump(['services' => $services], 3, 2), 0o640);
        $log->line(\sprintf('wrote %s: %s under %s', self::RELATIVE_PATH, implode(', ', array_keys($services)), $slice));

        return self::RELATIVE_PATH;
    }

    /**
     * After the start: the slice that should hold the limit must exist and have a quota. The nearest level that sets a
     * limit is checked (the template's, else the project's); with no limit set anywhere there is nothing to verify.
     *
     * @return string|null a warning, or null when the limit is in place or none is set
     */
    public function check(Instance $instance, Project $project): ?string
    {
        if (!$this->availability->isAvailable()) {
            return null;
        }
        $template = $this->templates->find($project, $instance->templateName());
        $name = $project->name();
        if (null !== $template?->cpuThreads()) {
            $unit = SliceName::template($name, $instance->templateName());
            $path = $this->cgroupRoot.'/'.SliceName::project($name).'/'.$unit;
        } elseif (null !== $project->cpuThreads()) {
            $unit = SliceName::project($name);
            $path = $this->cgroupRoot.'/'.$unit;
        } else {
            return null;
        }

        $quota = @file_get_contents($path.'/cpu.max');
        if (!\is_string($quota)) {
            return \sprintf('WARNING: the slice %s was not found after the start, so this instance runs without its CPU limit. Run "fleet host:slices" and restart the instance.', $unit);
        }
        if (str_starts_with(trim($quota), 'max')) {
            return \sprintf('WARNING: the slice %s has no CPU quota (cpu.max is "max"), so this instance runs without its CPU limit. Run "fleet host:slices" to apply the limits.', $unit);
        }

        return null;
    }
}
