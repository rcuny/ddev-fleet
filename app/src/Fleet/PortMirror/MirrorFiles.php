<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Fleet\Ssh\AtomicFile;

/**
 * The files of the mirror in the Traefik directory: the two config fragments (written atomically and only when their
 * content changed, removed when nothing is to be mirrored) and the failed-apply marker.
 *
 * The marker holds the public ports that were wanted when an apply failed (comma-separated, sorted). While it matches
 * the wanted ports nothing is written and the router is not restarted, so a broken mirror cannot restart the shared
 * router on every deploy. A different wanted set, or `fleet port:refresh`, clears it.
 */
final readonly class MirrorFiles
{
    public const int MODE = 0o644;

    public function __construct(private TraefikDirectory $traefik)
    {
    }

    /**
     * @return bool true when a file was written
     *
     * @throws \RuntimeException
     */
    public function write(MirrorPlan $plan): bool
    {
        if (!is_dir($this->traefik->dynamicDir()) && !@mkdir($this->traefik->dynamicDir(), 0o755, true) && !is_dir($this->traefik->dynamicDir())) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $this->traefik->dynamicDir()));
        }
        $changed = $this->writeIfChanged($this->traefik->staticFile(), MirrorRenderer::staticConfig($plan));

        return $this->writeIfChanged($this->traefik->dynamicFile(), MirrorRenderer::dynamicConfig($plan)) || $changed;
    }

    /**
     * @return bool true when a file was removed
     *
     * @throws \RuntimeException
     */
    public function remove(): bool
    {
        $removed = false;
        foreach ([$this->traefik->staticFile(), $this->traefik->dynamicFile()] as $file) {
            if (!file_exists($file)) {
                continue;
            }
            if (!@unlink($file)) {
                throw new \RuntimeException(\sprintf('Cannot remove "%s".', $file));
            }
            $removed = true;
        }

        return $removed;
    }

    /**
     * Both files exist with exactly what a sync would write now (or, when nothing is wanted, neither exists).
     */
    public function isCurrent(MirrorPlan $plan): bool
    {
        $static = $this->traefik->staticFile();
        $dynamic = $this->traefik->dynamicFile();
        if ($plan->isEmpty()) {
            return !file_exists($static) && !file_exists($dynamic);
        }

        return @file_get_contents($static) === MirrorRenderer::staticConfig($plan)
            && @file_get_contents($dynamic) === MirrorRenderer::dynamicConfig($plan);
    }

    /**
     * True while the marker matches the wanted ports. A marker for another set, or when nothing is wanted any more, is
     * deleted.
     */
    public function markerBlocks(MirrorPlan $plan): bool
    {
        $recorded = $this->markerPorts();
        if (null === $recorded) {
            return false;
        }
        if (!$plan->isEmpty() && $recorded === self::portsText($plan->publicPorts())) {
            return true;
        }
        try {
            $this->clearMarker();
        } catch (\RuntimeException) {
            return true;
        }

        return false;
    }

    public function markerPorts(): ?string
    {
        $text = @file_get_contents($this->traefik->markerFile());

        return false === $text ? null : trim($text);
    }

    /**
     * @param list<int> $publicPorts
     *
     * @throws \RuntimeException
     */
    public function writeMarker(array $publicPorts): void
    {
        AtomicFile::write($this->traefik->markerFile(), self::portsText($publicPorts)."\n", self::MODE);
    }

    /**
     * @return bool true when there was a marker
     *
     * @throws \RuntimeException
     */
    public function clearMarker(): bool
    {
        $file = $this->traefik->markerFile();
        if (!file_exists($file)) {
            return false;
        }
        if (!@unlink($file)) {
            throw new \RuntimeException(\sprintf('Cannot remove "%s".', $file));
        }

        return true;
    }

    /**
     * @param list<int> $ports
     */
    public static function portsText(array $ports): string
    {
        sort($ports);

        return implode(',', $ports);
    }

    private function writeIfChanged(string $file, string $contents): bool
    {
        if (@file_get_contents($file) === $contents) {
            return false;
        }
        AtomicFile::write($file, $contents, self::MODE);

        return true;
    }
}
