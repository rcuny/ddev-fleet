<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Fleet\Ddev\Ddev;
use App\Fleet\FleetPaths;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Workspace\Tmux;

/**
 * Takes an instance's checkout and Caddy site away. Nothing here uses privilege: when files cannot be removed (a
 * container wrote them as root), the job fails with the exact command for the operator and a retry finishes the job.
 */
final readonly class Teardown
{
    private const int ATTEMPTS = 3;

    public function __construct(
        private FleetPaths $paths,
        private Ddev $ddev,
        private InstanceSiteWriter $sites,
        private Tmux $tmux,
    ) {
    }

    /**
     * `ddev delete` (a failure is tolerated: the project may be half made, or ddev may already know nothing of it),
     * then the directory tree. A directory that is already gone is success, which makes a retry idempotent.
     *
     * @throws JobFailed with the `sudo rm -rf` line when something stays behind
     */
    public function removeCheckout(Instance $instance, JobLog $log): void
    {
        $dir = $this->paths->instanceDir($instance->id());

        $this->closeWindow($instance, $log);
        if (is_dir($dir)) {
            try {
                $result = $this->ddev->delete($dir, $instance->id(), $log->output(...));
                if (!$result->isSuccess()) {
                    $log->line(\sprintf('ddev delete failed with exit code %d; continuing.', $result->exitCode));
                }
            } catch (\Throwable $e) {
                $log->line('ddev delete could not run: '.$e->getMessage().'; continuing.');
            }
        }

        $this->removeTree($dir, $log);
    }

    /**
     * Closes the instance's tmux window. A destroy and a redeploy both pass here; the deploy builds a new window. A tmux
     * that is missing or fails is a log line, never a failed job.
     */
    public function closeWindow(Instance $instance, JobLog $log): void
    {
        try {
            if ($this->tmux->closeWindow($instance->id())) {
                $log->line('closed the tmux window');
            }
        } catch (\Throwable $e) {
            $log->line('WARNING: the tmux window could not be closed: '.$e->getMessage());
        }
    }

    public function removeSite(Instance $instance): void
    {
        $this->sites->remove($instance->id());
    }

    /**
     * @throws JobFailed
     */
    public function removeTree(string $dir, JobLog $log): void
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            self::delete($dir);
            clearstatcache(true, $dir);
            if (!file_exists($dir) && !is_link($dir)) {
                return;
            }
            $log->line(\sprintf('Could not remove %s completely (attempt %d of %d).', $dir, $attempt, self::ATTEMPTS));
            if ($attempt < self::ATTEMPTS) {
                usleep(200_000);
            }
        }

        throw new JobFailed(\sprintf('Files in the instance directory could not be removed. As an administrator run: sudo rm -rf %s and retry the job.', escapeshellarg($dir)));
    }

    /**
     * Removes the tree without following a symlink. Failures are left for the caller to notice.
     */
    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
