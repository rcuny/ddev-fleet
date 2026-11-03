<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\Host\HostSettingsRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Ssh\AtomicFile;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\AutheliaConfigRenderer;
use App\Fleet\System\PrivilegedApply;
use App\Fleet\System\SystemRenderer;
use Symfony\Component\Lock\LockFactory;

/**
 * Puts Authelia's main configuration into the running Authelia: renders it, and when it differs from what was
 * installed last, stages it and installs it through the root helper (`apply authelia`, which restarts Authelia and so
 * ends every session). Only host-level settings change that configuration (domain, notifier): users and project access
 * live in users.yml (AutheliaUsersFile), which Authelia reloads without a restart, so never call this after a user or
 * project change.
 *
 * Nothing happens before setup is complete: SetupFinisher installs the configuration, rules included.
 */
final readonly class AutheliaAccessSync
{
    public function __construct(
        private HostSettingsRepository $settings,
        private AutheliaConfigRenderer $config,
        private SystemRenderer $system,
        private FleetPaths $paths,
        private LockFactory $locks,
    ) {
    }

    /**
     * @param bool $force install even when the configuration equals the last installed one
     *
     * @return bool true when the configuration was installed; false when there was nothing to do (setup not complete,
     *              or no change since the last install)
     *
     * @throws \RuntimeException|\InvalidArgumentException when rendering or the helper fails; the database is not
     *                                                     affected, and the next call tries again
     */
    public function sync(bool $force = false): bool
    {
        $host = $this->settings->fresh();
        if (null === $host || !$host->isSetupComplete()) {
            return false;
        }

        // Two requests changing access at once: the second waits, then finds the configuration already installed.
        $lock = $this->locks->createLock('authelia-config', PrivilegedApply::TIMEOUT + 30.0, false);
        $lock->acquire(true);
        try {
            $digest = hash('sha256', $this->config->render($host));
            if (!$force && $digest === $this->installedDigest()) {
                return false;
            }
            $this->system->stageAndApply(ApplyTarget::Authelia, $host);
            $this->remember($digest);

            return true;
        } finally {
            $lock->release();
        }
    }

    private function installedDigest(): ?string
    {
        $path = $this->paths->autheliaConfigMarker();
        $stored = is_file($path) ? @file_get_contents($path) : false;

        return false === $stored ? null : trim($stored);
    }

    private function remember(string $digest): void
    {
        $dir = $this->paths->varDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the state directory.');
        }
        AtomicFile::write($this->paths->autheliaConfigMarker(), $digest."\n", 0o600);
    }
}
