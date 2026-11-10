<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Fleet\FleetPaths;

/**
 * The one exclusive lock (`<ssh dir>/.keys.lock`) that serialises everything that creates, replaces or deletes a key
 * file, and every template save that names extra keys (FLE-270): a key cannot be deleted between the moment a template
 * save checks it and the moment the template is stored. The lock is not re-entrant: never nest run() calls.
 */
final readonly class SshKeysLock
{
    public function __construct(private FleetPaths $paths)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        $dir = $this->paths->sshDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $dir));
        }
        if ((@fileperms($dir) & 0o777) !== 0o700 && !@chmod($dir, 0o700)) {
            throw new \RuntimeException(\sprintf('Cannot set mode 0700 on "%s".', $dir));
        }
        $lock = @fopen($dir.'/.keys.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            false !== $lock && fclose($lock);

            throw new \RuntimeException('Cannot lock the SSH key directory.');
        }
        try {
            return $work();
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }
}
