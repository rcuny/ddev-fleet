<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Job\Job;
use App\Fleet\FleetPaths;

/**
 * Serves a job log in pieces: `read($job, $from)` returns text from byte offset $from and the offset to ask for next.
 * ANSI escapes are removed and carriage-return progress is collapsed to its last state. While the job runs a piece
 * ends at the last newline, so a line is never shown half; a final job returns its tail.
 */
final readonly class LogReader
{
    public const int CHUNK = 65536;

    public function __construct(private FleetPaths $paths)
    {
    }

    public function size(Job $job): int
    {
        $path = $this->path($job);
        if (null === $path) {
            return 0;
        }
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * @return array{text: string, next: int, done: bool}
     */
    public function read(Job $job, int $from): array
    {
        $from = max(0, $from);
        $final = $job->status()->isFinal();
        $size = $this->size($job);
        if ($from >= $size) {
            return ['text' => '', 'next' => $final || $from > $size ? $size : $from, 'done' => $final];
        }

        $path = (string) $this->path($job);
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return ['text' => '', 'next' => $from, 'done' => false];
        }
        try {
            fseek($handle, $from);
            $raw = (string) fread($handle, self::CHUNK);
        } finally {
            fclose($handle);
        }

        $atEnd = $from + \strlen($raw) >= $size;
        if (!$atEnd || !$final) {
            $newline = strrpos($raw, "\n");
            if (false !== $newline) {
                $raw = substr($raw, 0, $newline + 1);
            } elseif ($atEnd) {
                // A line still being written: wait for its newline.
                return ['text' => '', 'next' => $from, 'done' => false];
            } else {
                $raw = self::withoutPartialCharacter($raw);
            }
        }

        $next = $from + \strlen($raw);

        return ['text' => self::clean($raw), 'next' => $next, 'done' => $final && $next >= $size];
    }

    private function path(Job $job): ?string
    {
        return null === $job->id() ? null : $this->paths->jobLog((string) $job->id(), $job->instanceId());
    }

    private static function clean(string $raw): string
    {
        $text = preg_replace('/\e\[[0-?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)|\e[@-Z\\\\-_]/', '', $raw) ?? $raw;
        $text = str_replace("\r\n", "\n", $text);
        if (str_contains($text, "\r")) {
            $lines = explode("\n", $text);
            foreach ($lines as $i => $line) {
                $parts = explode("\r", $line);
                $lines[$i] = '' === end($parts) && \count($parts) > 1 ? $parts[\count($parts) - 2] : (string) end($parts);
            }
            $text = implode("\n", $lines);
        }

        return $text;
    }

    /**
     * Drops an incomplete UTF-8 sequence from the end of a piece that was cut by size.
     */
    private static function withoutPartialCharacter(string $raw): string
    {
        $length = \strlen($raw);
        for ($back = 1; $back <= 3 && $back <= $length; ++$back) {
            $byte = \ord($raw[$length - $back]);
            if ($byte < 0x80) {
                return $raw;
            }
            if ($byte >= 0xC0) {
                $needed = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                return $back < $needed ? substr($raw, 0, $length - $back) : $raw;
            }
        }

        return $raw;
    }
}
