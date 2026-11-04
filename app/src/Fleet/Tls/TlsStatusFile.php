<?php

declare(strict_types=1);

namespace App\Fleet\Tls;

use App\Fleet\FleetPaths;

/**
 * `$FLEET_HOME/var/tls-status.json`: written by the timer job (fleet:host:tls-check) as the `fleet` user, read by the
 * app. Reading is a plain file read, safe inside a request; a missing or damaged file means "no status yet".
 */
final readonly class TlsStatusFile
{
    private const int MAX_BYTES = 65536;

    public function __construct(private FleetPaths $paths)
    {
    }

    public function path(): string
    {
        return $this->paths->varDir().'/tls-status.json';
    }

    public function read(): ?TlsStatus
    {
        $text = @file_get_contents($this->path(), false, null, 0, self::MAX_BYTES);
        $data = \is_string($text) ? json_decode($text, true) : null;

        return \is_array($data) ? TlsStatus::fromArray($data) : null;
    }

    public function write(TlsStatus $status): void
    {
        $path = $this->path();
        if (!is_dir(\dirname($path))) {
            throw new \RuntimeException('The state directory does not exist.');
        }
        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === file_put_contents($temp, json_encode($status->toArray(), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n") || !@chmod($temp, 0o644) || !@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Cannot write the TLS status file.');
        }
    }
}
