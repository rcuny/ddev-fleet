<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Host\IsolationMode;
use App\Domain\Instance\InstanceRepository;
use App\Fleet\Ddev\Ddev;
use App\Fleet\Ddev\DdevUnavailable;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\PortMirror\LiveEntrypoints;
use App\Fleet\PortMirror\TraefikDirectory;
use App\Fleet\Ssh\AtomicFile;

/**
 * FLE-326 (follow-up of FLE-299, section 7.4): keeps the router-path allow-list file in step with the instances. Only the
 * `enforce` mode writes it (report and off remove it). The file is a Traefik file-provider fragment, so the router reloads
 * it by itself: no restart. A container whose address changes is picked up by the next isolation pass (a Docker event,
 * about a second); until then its own requests for its own host name through the router answer the deny response.
 *
 * Never throws: whatever fails is returned as a message and the existing file is left alone (fail open, as the nftables part).
 */
final readonly class RouterAccess
{
    /** Where the router (a Traefik file provider on its own copy of the directory) reads global custom config. */
    public const string ROUTER_FILE = '/mnt/ddev-global-cache/traefik/config/'.RouterAccessRenderer::FILENAME;

    public function __construct(
        private InstanceRepository $instances,
        private InstanceHostnames $hostnames,
        private DockerNetworks $docker,
        private Ddev $ddev,
        private TraefikDirectory $traefik,
        private ?LiveEntrypoints $live = null,
    ) {
    }

    /**
     * @param list<ResolvedLink> $links the live links
     *
     * @return string|null a problem to log, null when the file is as wanted
     */
    public function sync(IsolationMode $mode, array $links): ?string
    {
        try {
            if (IsolationMode::Enforce !== $mode) {
                return $this->withdraw();
            }
            $gateway = $this->docker->gateway();
            if (null === $gateway) {
                return 'router access: the ddev_default gateway is unknown, the allow-list is left as it was';
            }
            $shared = $this->docker->sharedContainers();
            $entries = [];
            foreach ($this->instances->all() as $instance) {
                $id = $instance->id();
                $ips = [$gateway];
                foreach ($this->ddev->projectContainers($id) as $container) {
                    if (isset($shared[$container['name']])) {
                        $ips[] = $shared[$container['name']];
                    }
                }
                foreach ($links as $link) {
                    if ($link->targetInstance === $id && null !== $link->sourceContainer && isset($shared[$link->sourceContainer])) {
                        $ips[] = $shared[$link->sourceContainer];
                    }
                }
                $entries[$id] = new RouterAccessEntry($id, [$this->hostnames->primary($id), ...$this->hostnames->aliases($instance->project(), $id)], $ips);
            }
            ksort($entries);
            $text = RouterAccessRenderer::render(array_values($entries), $this->siteMirror(), $this->httpEntryPoints());
            if ('' === $text) {
                return $this->withdraw();
            }
            $file = $this->file();
            if (@file_get_contents($file) !== $text) {
                $dir = \dirname($file);
                if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
                    return 'router access: cannot create '.$dir;
                }
                AtomicFile::write($file, $text, 0o644);
            }

            return $this->push($text);
        } catch (DdevUnavailable $e) {
            return 'router access: Docker did not answer ('.$e->getMessage().'), the allow-list is left as it was';
        } catch (\Throwable $e) {
            return 'router access: '.$e->getMessage();
        }
    }

    public function file(): string
    {
        return $this->traefik->dynamicDir().'/'.RouterAccessRenderer::FILENAME;
    }

    /**
     * The report-and-off state: no host file, and no copy in the router. The router reads its own copy of the directory, which
     * DDEV refreshes only at a `ddev start`, so the copy is removed (and checked) here, and retried by the next pass while the
     * marker stays.
     */
    private function withdraw(): ?string
    {
        $hadFile = file_exists($this->file());
        if ($hadFile) {
            @unlink($this->file());
        }
        $marker = $this->traefik->routerAccessMarker();
        if (!$hadFile && !file_exists($marker)) {
            return null;
        }
        if (!$this->docker->routerRunning()) {
            // A stopped router reads the host directory again at its next `ddev start`, which no longer has the file.
            @unlink($marker);

            return null;
        }
        if (!$this->docker->routerRemove(self::ROUTER_FILE)) {
            $this->mark();

            return 'router access: the file could not be removed from the router, the next pass retries';
        }
        @unlink($marker);

        return null;
    }

    /** Makes the router's copy equal to $text (checked every pass: a `ddev start` or a manual change cannot leave it stale). */
    private function push(string $text): ?string
    {
        if (!$this->docker->routerRunning()) {
            return null;
        }
        if ($this->docker->routerRead(self::ROUTER_FILE) === $text) {
            $this->mark();

            return null;
        }
        $this->mark();
        if (!$this->docker->routerPut($this->file(), self::ROUTER_FILE)) {
            return 'router access: the file could not be copied into the router, the next pass retries';
        }

        return null;
    }

    private function mark(): void
    {
        if (!file_exists($this->traefik->routerAccessMarker())) {
            @file_put_contents($this->traefik->routerAccessMarker(), "1\n");
        }
    }

    /**
     * For `host:isolation:check`: whether the router's own copy is what the mode promises.
     *
     * @return array{expected: string, actual: string, ok: bool}|null null while the router is not running
     */
    public function verify(IsolationMode $mode): ?array
    {
        try {
            if (!$this->docker->routerRunning()) {
                return null;
            }
            $copy = $this->docker->routerRead(self::ROUTER_FILE);
            if (IsolationMode::Enforce !== $mode) {
                return ['expected' => 'absent', 'actual' => null === $copy ? 'absent' : 'present', 'ok' => null === $copy];
            }
            $host = @file_get_contents($this->file());
            $same = \is_string($host) && $copy === $host;

            return ['expected' => 'same as the host file', 'actual' => null === $copy ? 'missing' : ($same ? 'same' : 'different'), 'ok' => $same];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The router's entry points without the mirror's TCP ones (`fleet-mirror-*`): the HTTP deny routers must not sit on the `:443`
     * mirror, where they would terminate TLS for an instance's own name and answer 404. Empty when the API does not answer.
     *
     * @return list<string>
     */
    private function httpEntryPoints(): array
    {
        $names = $this->live?->names();
        if (null === $names) {
            return [];
        }

        return array_values(array_filter($names, static fn (string $n): bool => !str_starts_with($n, 'fleet-mirror-')));
    }

    /** The `:443` site mirror exists while the port mirror's static fragment declares its entry point. */
    private function siteMirror(): bool
    {
        $static = @file_get_contents($this->traefik->staticFile());

        return \is_string($static) && str_contains($static, RouterAccessRenderer::SITE_ENTRYPOINT);
    }
}
