<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use Symfony\Component\Lock\LockFactory;

/**
 * Serialises the writes of the mirror files with the router converge: two deploys must not rewrite the files while a
 * third restarts the router. A bounded wait, then MirrorLockBusy (callers treat it as "skipped", never fatal).
 *
 * Re-entrant within one process (FLE-336): a flock cannot be taken twice through two handles, so `host:isolation:enable` used to wait
 * the whole bound for a lock it held itself (the router restart, under the lock, runs an isolation pass that syncs the mirror). A nested
 * `run()` on the same object just runs; only the outermost call takes and releases the file lock.
 */
final class MirrorLock
{
    private const string KEY = 'router-mirror';
    private const float POLL_SECONDS = 0.5;

    private static int $depth = 0;

    public function __construct(private readonly LockFactory $locks, private readonly float $wait = 300.0)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws MirrorLockBusy
     */
    public function run(callable $work): mixed
    {
        if (self::$depth > 0) {
            ++self::$depth;
            try {
                return $work();
            } finally {
                --self::$depth;
            }
        }
        $lock = $this->locks->createLock(self::KEY, 900.0);
        $deadline = microtime(true) + $this->wait;
        while (!$lock->acquire(false)) {
            if (microtime(true) >= $deadline) {
                throw new MirrorLockBusy('another deploy holds the router-mirror lock');
            }
            usleep((int) (self::POLL_SECONDS * 1_000_000));
        }
        self::$depth = 1;
        try {
            return $work();
        } finally {
            self::$depth = 0;
            $lock->release();
        }
    }
}
