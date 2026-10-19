<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Fleet\FleetPaths;
use App\Fleet\Ssh\AtomicFile;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\CaddyReloader;
use App\Fleet\System\ConfigValue;
use Symfony\Component\Lock\LockFactory;
use Twig\Environment;

/**
 * Writes the Caddy site of one instance: a snippet `instances/<id>.conf` in the fleet's Caddy directory, which the main
 * Caddyfile imports. The worker writes it as the app user and asks the running Caddy to reload through the admin API;
 * nothing here is privileged and no `apply` target is involved.
 *
 * A site is never live without the gate: every request passes Authelia's forward_auth before it is proxied to ddev's
 * router. A snippet that Caddy refuses is rolled back, so one bad instance cannot take the other sites down.
 */
final readonly class InstanceSiteWriter
{
    public const int MODE = 0o640;
    private const float LOCK_SECONDS = 60.0;

    public function __construct(
        private Environment $twig,
        private FleetPaths $paths,
        private CaddyReloader $reloader,
        private InstanceHostnames $hostnames,
        private LockFactory $locks,
    ) {
    }

    /**
     * Renders, writes atomically and reloads; on a failure the previous file (or its absence) is put back.
     *
     * @throws \InvalidArgumentException for an id or host name that could change the Caddyfile's structure
     * @throws \RuntimeException         when Caddy refuses the config or cannot be reached
     */
    public function write(Instance $instance, Project $project): void
    {
        $file = $this->paths->instanceSiteFile($instance->id());
        $hosts = array_map(
            ConfigValue::domain(...),
            [$this->hostnames->primary($instance->id()), ...$this->hostnames->aliases($project, $instance->id())],
        );
        $contents = $this->twig->render('system/instance-site.twig', [
            'id' => $instance->id(),
            'hosts' => $hosts,
            'stripped_headers' => CaddyfileRenderer::strippedHeaders(),
            'authelia' => CaddyfileRenderer::AUTHELIA,
            'router' => CaddyfileRenderer::DDEV_ROUTER,
        ]);
        $this->ensureDir();

        $lock = $this->locks->createLock('caddy.sites', self::LOCK_SECONDS);
        $lock->acquire(true);
        try {
            $previous = is_file($file) ? @file_get_contents($file) : null;
            if (false === $previous) {
                throw new \RuntimeException('Cannot read the current instance site; refusing to replace what cannot be restored.');
            }

            AtomicFile::write($file, $contents, self::MODE);
            try {
                $this->reloader->validateAndReload();
            } catch (\Throwable $e) {
                $this->restore($file, $previous, $e);

                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Deletes the site and reloads; a site that does not exist is fine.
     */
    public function remove(string $instanceId): void
    {
        $file = $this->paths->instanceSiteFile($instanceId);

        $lock = $this->locks->createLock('caddy.sites', self::LOCK_SECONDS);
        $lock->acquire(true);
        try {
            if (!is_file($file)) {
                return;
            }
            if (!@unlink($file)) {
                throw new \RuntimeException('Cannot remove the instance site.');
            }
            $this->reloader->validateAndReload();
        } finally {
            $lock->release();
        }
    }

    /**
     * The redeploy preflight: Caddy accepts the config as it is, so a redeploy does not destroy a checkout it cannot
     * serve again.
     *
     * @throws \RuntimeException
     */
    public function validate(): void
    {
        $this->reloader->validate();
    }

    private function restore(string $file, ?string $previous, \Throwable $cause): void
    {
        try {
            if (null === $previous) {
                if (is_file($file) && !@unlink($file)) {
                    throw new \RuntimeException('Cannot remove the new instance site.');
                }

                return;
            }
            AtomicFile::write($file, $previous, self::MODE);
        } catch (\Throwable) {
            throw new \RuntimeException('Caddy refused the new instance site and the previous one could not be restored; fix '.$file.' by hand.', 0, $cause);
        }
    }

    /**
     * Inside FLEET_HOME the directory is ours; in production the installer owns it (`fleet:caddy`, setgid) and the app
     * only checks that it exists.
     */
    private function ensureDir(): void
    {
        $dir = $this->paths->caddyInstancesDir();
        if (is_dir($dir)) {
            return;
        }
        if (!str_starts_with($dir.'/', $this->paths->home().'/') || (!@mkdir($dir, 0o750, true) && !is_dir($dir))) {
            throw new \RuntimeException(\sprintf('The Caddy instance directory "%s" is missing; run the installer again.', $dir));
        }
    }
}
