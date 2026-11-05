<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `busy` of a slot pool (FLE-200, spec 4 option C): a run holds an flock on a file in the pool's directory,
 * /srv/fleet/slots/<project>/<template>/ (template pool) or /srv/fleet/slots/<project>/_project/ (project pool; `_`
 * cannot collide with a template name). Busy = the files whose lock is held right now. A missing directory = 0.
 *
 * FLE-212: the state is read from /proc/locks (device and inode of every lock the kernel holds), never by taking a
 * lock. A probe lock (even a shared, non-blocking one for an instant) could make a runner's `flock -n` fail, and
 * would report a shared-lock holder as free. Whatever cannot be determined (a pool directory that cannot be read,
 * a file that cannot be stat'ed, an unreadable /proc/locks) is counted in `unreadable`, never skipped silently.
 */
final readonly class SlotLocks
{
    public const string PROJECT_POOL = '_project';

    public function __construct(
        #[Autowire('%fleet.slots_dir%')]
        private string $dir,
        #[Autowire('%fleet.proc_locks_file%')]
        private string $procLocks = '/proc/locks',
    ) {
    }

    /** The number of lock files of the pool that are locked right now. */
    public function busy(string $project, ?string $template): int
    {
        return $this->inspect($project, $template)['busy'];
    }

    /**
     * @return array{busy: int, unreadable: int} `unreadable` = pool directories, lock files or lock tables that could
     *                                           not be read, so `busy` may be too low by that many
     */
    public function inspect(string $project, ?string $template): array
    {
        $pool = $this->dir.'/'.$project.'/'.($template ?? self::PROJECT_POOL);
        if (!is_dir($pool)) {
            return ['busy' => 0, 'unreadable' => 0];
        }
        $entries = @scandir($pool);
        if (false === $entries) {
            return ['busy' => 0, 'unreadable' => 1];
        }

        $files = [];
        $unreadable = 0;
        foreach ($entries as $entry) {
            $path = $pool.'/'.$entry;
            if ('.' === $entry[0] || is_link($path) || !is_file($path)) {
                continue;
            }
            $stat = @stat($path);
            if (false === $stat) {
                ++$unreadable;
                continue;
            }
            $files[] = [$stat['dev'], $stat['ino']];
        }
        if ([] === $files) {
            return ['busy' => 0, 'unreadable' => $unreadable];
        }

        $locked = $this->lockedInodes();
        if (null === $locked) {
            return ['busy' => 0, 'unreadable' => $unreadable + \count($files)];
        }
        $busy = 0;
        foreach ($files as [$dev, $ino]) {
            if (isset($locked[self::deviceId($dev).':'.$ino])) {
                ++$busy;
            }
        }

        return ['busy' => $busy, 'unreadable' => $unreadable];
    }

    /** The `major:minor` of a stat() `dev`, as /proc/locks prints it (hexadecimal, minimal digits). */
    public static function deviceId(int $dev): string
    {
        $major = (($dev >> 8) & 0xFFF) | (($dev >> 32) & ~0xFFF);
        $minor = ($dev & 0xFF) | (($dev >> 12) & ~0xFF);

        return \sprintf('%02x:%02x', $major, $minor);
    }

    /**
     * The set of `major:minor:inode` that hold a lock now, or null when /proc/locks cannot be read. Lines that start
     * with `->` are waiters, not holders.
     *
     * @return array<string, true>|null
     */
    private function lockedInodes(): ?array
    {
        $text = @file_get_contents($this->procLocks);
        if (!\is_string($text)) {
            return null;
        }
        $locked = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (1 === preg_match('/^\d+:\s+(?:FLOCK|POSIX|OFDLCK)\s+\S+\s+\S+\s+\d+\s+([0-9a-f]+:[0-9a-f]+):(\d+)\s/i', $line, $m)) {
                [$major, $minor] = explode(':', strtolower($m[1]));
                $locked[\sprintf('%02x:%02x', hexdec($major), hexdec($minor)).':'.$m[2]] = true;
            }
        }

        return $locked;
    }
}
