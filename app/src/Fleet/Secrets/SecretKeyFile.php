<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

use App\Fleet\FleetPaths;

/**
 * The secretbox key on disk (FleetPaths::secretKey()): exactly 32 random bytes, mode 0600, owned by the user that
 * runs the app. Created atomically and never overwritten; an existing file that is the wrong size, a symlink, owned
 * by someone else or readable by group/other is refused rather than repaired, because repairing could hide a leak.
 */
final readonly class SecretKeyFile
{
    public function __construct(private FleetPaths $paths)
    {
    }

    /**
     * Creates the key if it does not exist yet, otherwise validates the existing one. Safe to call concurrently:
     * the loser of the race validates the winner's file.
     */
    public function ensure(): void
    {
        $path = $this->paths->secretKey();
        if (file_exists($path) || is_link($path)) {
            $this->read($path, true);

            return;
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create the secret key directory "%s".', $dir));
        }

        // The key is written to a private temp file in the same directory, then hard-linked into place: link()
        // fails if the target exists, so a concurrent creator can never be overwritten, and the key never appears
        // under its final name with partial content or loose permissions.
        $tmp = $dir.'/.secret.key.'.bin2hex(random_bytes(8)).'.tmp';
        $previousUmask = umask(0o077);
        try {
            $handle = @fopen($tmp, 'x');
        } finally {
            umask($previousUmask);
        }
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot create a temporary secret key file in "%s".', $dir));
        }

        $key = sodium_crypto_secretbox_keygen();
        try {
            $written = @chmod($tmp, 0o600) ? fwrite($handle, $key) : false;
            $synced = $written === \strlen($key) && fflush($handle);
            $synced && \function_exists('fsync') && fsync($handle);
            fclose($handle);
            if (!$synced) {
                throw new \RuntimeException('Cannot write the secret key file.');
            }
            if (!@link($tmp, $path)) {
                if (!file_exists($path)) {
                    throw new \RuntimeException(\sprintf('Cannot install the secret key file at "%s".', $path));
                }
                $this->read($path, true);
            }
        } finally {
            sodium_memzero($key);
            @unlink($tmp);
        }
    }

    /**
     * The raw key. The caller should sodium_memzero() its copy when done. Never creates the key: a missing key next
     * to existing ciphertext means data loss, and silently minting a new one would hide that.
     */
    public function key(): string
    {
        return $this->read($this->paths->secretKey(), false);
    }

    private function read(string $path, bool $discard): string
    {
        $link = @lstat($path);
        if (false === $link || (($link['mode'] & 0o170000) !== 0o100000)) {
            throw new \RuntimeException(\sprintf('The secret key "%s" is missing or is not a regular file.', $path));
        }

        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('The secret key "%s" is not readable.', $path));
        }

        try {
            $stat = fstat($handle);
            if (false === $stat || $stat['ino'] !== $link['ino'] || $stat['dev'] !== $link['dev']) {
                throw new \RuntimeException(\sprintf('The secret key "%s" changed while it was opened.', $path));
            }
            if (0 !== ($stat['mode'] & 0o077)) {
                throw new \RuntimeException(\sprintf('The secret key "%s" has mode %04o; group/other access is not allowed (expected 0600).', $path, $stat['mode'] & 0o777));
            }
            if (\function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
                throw new \RuntimeException(\sprintf('The secret key "%s" is not owned by the user running the app.', $path));
            }
            if (\SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== $stat['size']) {
                throw new \RuntimeException(\sprintf('The secret key "%s" must be exactly %d bytes.', $path, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            }
            $key = stream_get_contents($handle, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES + 1);
        } finally {
            fclose($handle);
        }

        if (!\is_string($key) || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            \is_string($key) && sodium_memzero($key);

            throw new \RuntimeException(\sprintf('The secret key "%s" must be exactly %d bytes.', $path, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }

        if ($discard) {
            sodium_memzero($key);

            return '';
        }

        return $key;
    }
}
