<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Ssh\AtomicFile;

/**
 * Edits the project's own `.env` in place: the file belongs to the project authors, so comments, blank lines and key
 * order stay byte for byte.
 */
final class EnvFile
{
    private function __construct()
    {
    }

    /**
     * Replaces the first `KEY=` line where it stands, or appends one; creates the file when it is missing. Only the
     * first assignment is touched: a later duplicate stays visible.
     */
    public static function setVar(string $path, string $key, string $value): void
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) || 1 === preg_match('/[\r\n\0]/', $value)) {
            throw new \InvalidArgumentException('The environment variable is not valid.');
        }

        $lines = is_file($path) ? (preg_split('/\R/', rtrim((string) file_get_contents($path), "\r\n")) ?: []) : [];
        if ([''] === $lines) {
            $lines = [];
        }

        $prefix = $key.'=';
        $replaced = false;
        foreach ($lines as $i => $line) {
            if (str_starts_with(ltrim($line), $prefix)) {
                $lines[$i] = $prefix.$value;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $lines[] = $prefix.$value;
        }

        $mode = is_file($path) ? (fileperms($path) & 0o777) : 0o640;
        AtomicFile::write($path, implode("\n", $lines)."\n", $mode);
    }
}
