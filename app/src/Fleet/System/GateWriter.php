<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Fleet\FleetPaths;
use App\Fleet\Ssh\AtomicFile;
use Twig\Environment;

/**
 * Writes the Caddy gate snippet (FleetPaths::gateFile()) and makes Caddy use it. The main Caddyfile imports that
 * file by literal path from inside the identity route, so flipping the gate never touches root-owned config.
 *
 * Writing is transactional: the previous file is kept in memory, and when Caddy rejects the new config the previous
 * content (or its absence) is put back before the error is rethrown.
 */
final readonly class GateWriter
{
    public const int MODE = 0o640;

    public function __construct(
        private Environment $twig,
        private FleetPaths $paths,
        private CaddyReloader $reloader,
    ) {
    }

    /**
     * What the gate file contains for a mode.
     */
    public function render(GateMode $mode): string
    {
        return $this->twig->render($mode->template(), [
            'authelia' => CaddyfileRenderer::AUTHELIA,
            // These are exactly the headers RemoteUserAuthenticator reads; the Caddyfile strips them all before this.
            'copy_headers' => array_map(static fn (string $field): string => 'Remote-'.$field, CaddyfileRenderer::IDENTITY_FIELDS),
        ]);
    }

    /**
     * @param bool $reload false only writes the file: the installer renders the gate before the main Caddyfile is
     *                     installed, when there is no Caddy to validate against or reload
     *
     * @throws \RuntimeException when the snippet directory is unusable, the file cannot be written, or Caddy refuses
     *                           the result (the previous gate is restored first)
     */
    public function write(GateMode $mode, bool $reload = true): void
    {
        $contents = $this->render($mode);
        $this->ensureSnippetDir();
        $file = $this->paths->gateFile();

        $lock = $this->lock();
        try {
            $previous = is_file($file) ? @file_get_contents($file) : null;
            if (false === $previous) {
                throw new \RuntimeException('Cannot read the current gate file; refusing to replace what cannot be restored.');
            }

            AtomicFile::write($file, $contents, self::MODE);
            if (!$reload) {
                return;
            }
            try {
                $this->reloader->validateAndReload();
            } catch (\Throwable $e) {
                $this->restore($file, $previous, $e);

                throw $e;
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    private function restore(string $file, ?string $previous, \Throwable $cause): void
    {
        try {
            if (null === $previous) {
                if (is_file($file) && !@unlink($file)) {
                    throw new \RuntimeException('Cannot remove the new gate file.');
                }

                return;
            }
            AtomicFile::write($file, $previous, self::MODE);
        } catch (\Throwable) {
            throw new \RuntimeException('Caddy refused the new gate and the previous one could not be restored; fix '.$file.' by hand.', 0, $cause);
        }
    }

    /**
     * Inside FLEET_HOME the directory is ours and is created on demand. Anywhere else (production: /etc/caddy/fleet)
     * the installer owns it: a missing directory, or one that lost its setgid bit, would let the app write files that
     * Caddy cannot read, and the failure would only show at the next Caddy restart. An unprivileged user cannot set
     * setgid for a group it is not in, so the app refuses instead of repairing.
     */
    private function ensureSnippetDir(): void
    {
        $dir = $this->paths->caddySnippetDir();
        if (str_starts_with($dir.'/', $this->paths->home().'/')) {
            if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Cannot create the Caddy snippet directory "%s".', $dir));
            }

            return;
        }

        clearstatcache(true, $dir);
        if (!is_dir($dir) || 0 === (@fileperms($dir) & 0o2000)) {
            $arg = escapeshellarg($dir);

            throw new \RuntimeException(\sprintf('The Caddy snippet directory %1$s is missing or lost its setgid bit; Caddy could not read what the app writes there. As root: chgrp -R caddy %1$s; find %1$s -type d -exec chmod 2750 {} +; find %1$s -type f -exec chmod 0640 {} +; systemctl restart caddy', $arg));
        }
    }

    /**
     * @return resource
     */
    private function lock()
    {
        $dir = $this->paths->locksDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the locks directory.');
        }
        $lock = @fopen($dir.'/caddy-gate.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the Caddy gate file.');
        }

        return $lock;
    }
}
