<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Domain\Host\HostSettings;
use App\Fleet\FleetPaths;
use App\Fleet\Ssh\AtomicFile;

/**
 * Renders the root-installed config into the staging area (FleetPaths::stagingFile()) for `apply <target>` and
 * flips the Caddy gate. The staged files are inputs for the helper only: they are removed again after every apply,
 * successful or not, because the Caddy environment file holds the OVH credentials in clear.
 */
final readonly class SystemRenderer
{
    private const int ENV_MODE = 0o600;
    private const int CONFIG_MODE = 0o640;

    /** target => file names the helper may find in its staging directory. */
    private const array FILES = [
        'caddy' => ['Caddyfile', 'env'],
        'authelia' => ['configuration.yml'],
    ];

    public function __construct(
        private CaddyfileRenderer $caddyfile,
        private CaddyEnvRenderer $caddyEnv,
        private AutheliaConfigRenderer $authelia,
        private GateWriter $gate,
        private FleetPaths $paths,
        private Applier $applier,
    ) {
    }

    /**
     * Renders everything first, then writes: a render that throws (missing OVH secret, invalid domain) leaves the
     * staging area untouched.
     *
     * @param ApplyTarget|null $only stage just this target's files (null: all of them)
     */
    public function renderStaging(HostSettings $host, ?ApplyTarget $only = null): void
    {
        $files = [];
        if (null === $only || ApplyTarget::Caddy === $only) {
            $files[] = ['caddy', 'Caddyfile', $this->caddyfile->render($host), self::CONFIG_MODE];
            $files[] = ['caddy', 'env', $this->caddyEnv->render($host), self::ENV_MODE];
        }
        if (null === $only || ApplyTarget::Authelia === $only) {
            $files[] = ['authelia', 'configuration.yml', $this->authelia->render($host), self::CONFIG_MODE];
        }

        foreach ($files as [$target, $name, $contents, $mode]) {
            $path = $this->paths->stagingFile($target, $name);
            $this->ensureDir(\dirname($path));
            AtomicFile::write($path, $contents, $mode);
        }
    }

    /**
     * Stages one target, has the helper install it, and removes the staged files whatever happens.
     *
     * @throws \RuntimeException when rendering or the helper fails
     */
    public function stageAndApply(ApplyTarget $target, HostSettings $host): void
    {
        try {
            $this->renderStaging($host, $target);
            $this->applier->apply($target);
        } finally {
            $this->discardStaging($target);
        }
    }

    /**
     * Removes every file staged for a target (including temp files left by an interrupted write).
     */
    public function discardStaging(ApplyTarget $target): void
    {
        $dir = \dirname($this->paths->stagingFile($target->value, self::FILES[$target->value][0]));
        $entries = is_dir($dir) ? scandir($dir) : false;
        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' !== $entry && '..' !== $entry && !is_dir($dir.'/'.$entry)) {
                @unlink($dir.'/'.$entry);
            }
        }
    }

    /**
     * Writes the Caddy gate for the current state of setup and reloads Caddy (see GateWriter::write()).
     *
     * @param GateMode|null $force use this mode whatever the setup state (null: Setup until setup is complete)
     */
    public function renderGate(HostSettings $host, ?GateMode $force = null): GateMode
    {
        $mode = $force ?? ($host->isSetupComplete() ? GateMode::Authelia : GateMode::Setup);
        $this->gate->write($mode);

        return $mode;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the staging directory.');
        }
        if ((@fileperms($dir) & 0o777) !== 0o700 && !@chmod($dir, 0o700)) {
            throw new \RuntimeException('Cannot restrict the staging directory.');
        }
    }
}
