<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * Writes files so that a reader never sees partial content and a private file never exists with loose permissions.
 */
final class AtomicFile
{
    private function __construct()
    {
    }

    /**
     * Writes $contents to a fresh temp file next to $path (mode applied before any content is written), then renames
     * it over $path.
     */
    public static function write(string $path, string $contents, int $mode): void
    {
        $tmp = self::tempPath(\dirname($path), '.'.basename($path));
        try {
            self::create($tmp, $contents, $mode);
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException(\sprintf('Cannot install "%s".', $path));
            }
        } finally {
            // Nothing is left behind on failure; after a successful rename the temp name no longer exists.
            @unlink($tmp);
        }
    }

    /**
     * Creates $path exclusively (fails if it exists) with $mode and $contents. For short-lived files the caller
     * removes itself.
     */
    public static function create(string $path, string $contents, int $mode): void
    {
        $previousUmask = umask(0o077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($previousUmask);
        }
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot create a temporary file in "%s".', \dirname($path)));
        }

        try {
            if (!@chmod($path, $mode) || \strlen($contents) !== fwrite($handle, $contents) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException(\sprintf('Cannot write "%s".', basename($path)));
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);

            throw $e;
        }
        fclose($handle);
    }

    public static function tempPath(string $dir, string $prefix): string
    {
        return $dir.'/'.$prefix.'.'.bin2hex(random_bytes(8)).'.tmp';
    }
}
