<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `busy` of a slot pool (FLE-200, spec 4 option C): a run holds an flock on a file in the pool's directory,
 * /srv/fleet/slots/<project>/<template>/ (template pool) or /srv/fleet/slots/<project>/_project/ (project pool; `_`
 * cannot collide with a template name). Busy = the files whose lock is held right now. A missing directory = 0.
 * The probe takes a shared non-blocking lock for an instant, released at once, so a free file stays free.
 */
final readonly class SlotLocks
{
    public const string PROJECT_POOL = '_project';

    public function __construct(
        #[Autowire('%fleet.slots_dir%')]
        private string $dir,
    ) {
    }

    public function busy(string $project, ?string $template): int
    {
        $pool = $this->dir.'/'.$project.'/'.($template ?? self::PROJECT_POOL);
        $busy = 0;
        foreach (@scandir($pool) ?: [] as $entry) {
            $path = $pool.'/'.$entry;
            if ('.' === $entry[0] || !is_file($path) || is_link($path)) {
                continue;
            }
            $handle = @fopen($path, 'r');
            if (false === $handle) {
                continue;
            }
            if (flock($handle, \LOCK_SH | \LOCK_NB)) {
                flock($handle, \LOCK_UN);
            } else {
                ++$busy;
            }
            fclose($handle);
        }

        return $busy;
    }
}
