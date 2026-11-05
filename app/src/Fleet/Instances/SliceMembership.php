<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\InstanceRepository;
use App\Fleet\FleetPaths;
use App\Fleet\System\SlicesAvailability;

/**
 * The instances that are "not under a slice" (capacity spec section 3, host page): checkouts without the cgroup
 * override, deployed before the feature or while the host had no `slices` helper. They get their slice at the next
 * redeploy or restart. Empty on a host that cannot constrain instances at all, where listing every instance would only
 * be noise.
 */
final readonly class SliceMembership
{
    public function __construct(
        private InstanceRepository $instances,
        private FleetPaths $paths,
        private SlicesAvailability $availability,
    ) {
    }

    /**
     * @return list<string> instance ids
     */
    public function outside(): array
    {
        if (!$this->availability->isAvailable()) {
            return [];
        }
        $outside = [];
        foreach ($this->instances->ids() as $id) {
            $dir = $this->paths->instanceDir($id);
            if (is_dir($dir.'/.ddev') && !is_file($dir.'/'.InstanceCgroupWriter::RELATIVE_PATH)) {
                $outside[] = $id;
            }
        }
        sort($outside);

        return $outside;
    }
}
