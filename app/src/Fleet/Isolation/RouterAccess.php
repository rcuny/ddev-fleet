<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Host\IsolationMode;
use App\Domain\Instance\InstanceRepository;
use App\Fleet\Ddev\Ddev;
use App\Fleet\Ddev\DdevUnavailable;
use App\Fleet\Instances\InstanceHostnames;
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
    public function __construct(
        private InstanceRepository $instances,
        private InstanceHostnames $hostnames,
        private DockerNetworks $docker,
        private Ddev $ddev,
        private TraefikDirectory $traefik,
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
                $this->remove();

                return null;
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
            $text = RouterAccessRenderer::render(array_values($entries), $this->siteMirror());
            if ('' === $text) {
                $this->remove();

                return null;
            }
            $file = $this->file();
            if (@file_get_contents($file) === $text) {
                return null;
            }
            $dir = \dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
                return 'router access: cannot create '.$dir;
            }
            AtomicFile::write($file, $text, 0o644);

            return null;
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

    private function remove(): void
    {
        if (file_exists($this->file())) {
            @unlink($this->file());
        }
    }

    /** The `:443` site mirror exists while the port mirror's static fragment declares its entry point. */
    private function siteMirror(): bool
    {
        $static = @file_get_contents($this->traefik->staticFile());

        return \is_string($static) && str_contains($static, RouterAccessRenderer::SITE_ENTRYPOINT);
    }
}
