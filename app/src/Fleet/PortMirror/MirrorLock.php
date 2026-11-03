<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use Symfony\Component\Lock\LockFactory;

/**
 * Serialises the writes of the mirror files with the router converge: two deploys must not rewrite the files while a
 * third restarts the router. A bounded wait, then MirrorLockBusy (callers treat it as "skipped", never fatal).
 */
final readonly class MirrorLock
{
    private const string KEY = 'router-mirror';
    private const float POLL_SECONDS = 0.5;

    public function __construct(private LockFactory $locks, private float $wait = 300.0)
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
        $lock = $this->locks->createLock(self::KEY, 900.0);
        $deadline = microtime(true) + $this->wait;
        while (!$lock->acquire(false)) {
            if (microtime(true) >= $deadline) {
                throw new MirrorLockBusy('another deploy holds the router-mirror lock');
            }
            usleep((int) (self::POLL_SECONDS * 1_000_000));
        }
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
