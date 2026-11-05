<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Port\NamedPort;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\ProjectGroup;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\Rule\PortNumbers;
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
 * The project's named ports get one more block each (`<host>:<public port>`), behind the same gate and proxied to the
 * port on ddev's router.
 *
 * A site is never live without the gate: every request passes Authelia's forward_auth before it is proxied to ddev's
 * router, and is then answered 403 unless Authelia's Remote-Groups carries the administrators' group or the project's
 * group (ProjectGroup). Authelia itself lets any signed-in user through its one static rule, so access changes are
 * edits of users.yml and never restart it. The aliases and the named-port blocks of the instance are the same site. A snippet that Caddy refuses is rolled back, so one bad instance cannot take the other sites down.
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
        private AdminGroup $admins,
        private ProjectRepository $projects,
    ) {
    }

    /**
     * Renders, writes atomically and reloads; on a failure the previous file (or its absence) is put back.
     * `$reload = false` only writes: the host render uses it while the main Caddyfile is about to change layout, so
     * the current one cannot validate the new snippets (the root helper validates the pair when it installs).
     *
     * @throws \InvalidArgumentException for an id or host name that could change the Caddyfile's structure
     * @throws \RuntimeException         when Caddy refuses the config or cannot be reached
     */
    public function write(Instance $instance, Project $project, bool $reload = true): void
    {
        $file = $this->paths->instanceSiteFile($instance->id());
        $portsFile = $this->paths->instanceSitesFile($instance->id());
        $wildcard = $this->hostnames->wildcard();
        $hosts = array_map(
            ConfigValue::domain(...),
            [$this->hostnames->primary($instance->id()), ...$this->hostnames->aliases($project, $instance->id())],
        );
        // Transition: a name that already holds a stored certificate keeps its own exact-name site (Caddy prefers it to
        // the wildcard, so it keeps serving that certificate and never needs a new order); the rest sit under the wildcard.
        $exact = $wildcard ? $this->hostnames->withStoredCertificate($hosts, $this->paths->storedCertificatesFile()) : [];
        $context = [
            'id' => $instance->id(),
            'wildcard' => $wildcard,
            'hosts' => array_values(array_diff($hosts, $exact)),
            'exact_hosts' => $exact,
            'ports' => self::portBlocks($project, $hosts),
            'stripped_headers' => CaddyfileRenderer::strippedHeaders(),
            'authelia' => CaddyfileRenderer::AUTHELIA,
            'groups_pattern' => $this->groupsPattern($project),
            'router' => CaddyfileRenderer::DDEV_ROUTER,
            'router_host' => CaddyfileRenderer::DDEV_ROUTER_HOST,
        ];
        // Wildcard layout: the site is a fragment of the host's wildcard site and the named ports are a second file.
        // The other layout has everything in the one file and no ports file.
        $files = [
            $file => $this->twig->render('system/instance-site.twig', $context),
            $this->paths->portsFallbackFile() => $this->fallbackSite($project),
            $portsFile => $wildcard && ([] !== $context['ports'] || [] !== $exact) ? $this->twig->render('system/instance-sites.twig', $context) : null,
        ];
        $this->ensureDir();

        $lock = $this->locks->createLock('caddy.sites', self::LOCK_SECONDS);
        $lock->acquire(true);
        try {
            $previous = [];
            foreach ($files as $path => $unused) {
                $current = is_file($path) ? @file_get_contents($path) : null;
                if (false === $current) {
                    throw new \RuntimeException('Cannot read the current instance site; refusing to replace what cannot be restored.');
                }
                $previous[$path] = $current;
            }

            if ($previous === $files) {
                // Nothing changed: no write and no reload, which would interrupt the certificate challenges in flight.
                return;
            }

            try {
                foreach ($files as $path => $contents) {
                    $this->put($path, $contents);
                }
                if ($reload) {
                    $this->reloader->validateAndReload();
                }
            } catch (\Throwable $e) {
                foreach ($previous as $path => $contents) {
                    $this->restore($path, $contents, $e);
                }

                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Deletes the site (and its named-port file) and reloads; a site that does not exist is fine.
     */
    public function remove(string $instanceId): void
    {
        $files = [$this->paths->instanceSiteFile($instanceId), $this->paths->instanceSitesFile($instanceId)];

        $lock = $this->locks->createLock('caddy.sites', self::LOCK_SECONDS);
        $lock->acquire(true);
        try {
            $any = false;
            $fallback = $this->paths->portsFallbackFile();
            $current = is_file($fallback) ? @file_get_contents($fallback) : null;
            $wanted = $this->fallbackSite();
            if (false !== $current && $current !== $wanted) {
                $this->put($fallback, $wanted);
                $any = true;
            }
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }
                if (!@unlink($file)) {
                    throw new \RuntimeException('Cannot remove the instance site.');
                }
                $any = true;
            }
            if ($any) {
                $this->reloader->validateAndReload();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * The 404 catch-all of the named ports: one block per distinct public port of every project (plus `$extra`, a
     * project not saved yet), or null when there is none.
     */
    private function fallbackSite(?Project $extra = null): ?string
    {
        $public = [];
        foreach ([...$this->projects->all(), ...(null === $extra ? [] : [$extra])] as $project) {
            foreach ($project->ports() as $port) {
                $public[ConfigValue::port($port->publicPort())] = true;
            }
        }
        if ([] === $public) {
            return null;
        }
        ksort($public);

        return $this->twig->render('system/ports-fallback.twig', ['public_ports' => array_keys($public)]);
    }

    private function put(string $path, ?string $contents): void
    {
        if (null === $contents) {
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('Cannot remove the stale instance port file.');
            }

            return;
        }
        AtomicFile::write($path, $contents, self::MODE);
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

    /**
     * The RE2 pattern the Remote-Groups header (comma separated) must match: the admin group or the project's group as
     * a whole list entry. Both names are validated (letters, digits, dot, underscore, dash) and quoted.
     */
    private function groupsPattern(Project $project): string
    {
        $groups = [ConfigValue::group($this->admins->name()), ProjectGroup::name($project->name())];

        return '(?:^|,)(?:'.implode('|', array_map(static fn (string $group): string => preg_quote($group, '/'), $groups)).')(?:,|$)';
    }

    /**
     * One block per named port of the project, ordered by public port: every host of the instance with the public port
     * appended (the certificate follows the plain host).
     *
     * @param list<string> $hosts validated
     *
     * @return list<array{name: string, public: int, router: int, hosts: list<string>}>
     *
     * @throws \InvalidArgumentException for a port that Caddy or the DDEV router already uses
     */
    private static function portBlocks(Project $project, array $hosts): array
    {
        $ports = $project->ports();
        usort($ports, static fn (NamedPort $a, NamedPort $b): int => $a->publicPort() <=> $b->publicPort());

        $blocks = [];
        foreach ($ports as $port) {
            $error = PortNumbers::publicError($port->publicPort()) ?? PortNumbers::routerError($port->routerPort(), $port->publicPort());
            if (null !== $error) {
                throw new \InvalidArgumentException(\sprintf('Named port "%s" cannot be served: %s', $port->name(), $error));
            }
            $public = ConfigValue::port($port->publicPort());
            $blocks[] = [
                'name' => ConfigValue::portName($port->name()),
                'public' => $public,
                'router' => ConfigValue::port($port->routerPort()),
                'hosts' => array_map(static fn (string $host): string => $host.':'.$public, $hosts),
            ];
        }

        return $blocks;
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
