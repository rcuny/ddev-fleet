<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Job\Job;

/**
 * The append-only log file of one job. Everything goes through the masker before it is written, and each write is
 * flushed so a reader sees it at once. The feed is told about growth at most every $notifyEvery seconds.
 */
final class JobLog
{
    private int $size;
    private float $lastNotified = 0.0;

    public function __construct(
        private readonly string $path,
        private readonly LogMasker $masker,
        private readonly JobFeed $feed,
        private readonly Job $job,
        private readonly float $notifyEvery = 0.25,
    ) {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the job log directory.');
        }
        clearstatcache(true, $path);
        $this->size = is_file($path) ? (int) filesize($path) : 0;
    }

    public function line(string $message): void
    {
        $this->write(\sprintf("[%s] %s\n", gmdate('Y-m-d\TH:i:s\Z'), $message));
    }

    /**
     * The $onOutput callback of a CommandRunner call.
     */
    public function output(string $stream, string $chunk): void
    {
        $this->write($chunk);
    }

    public function size(): int
    {
        return $this->size;
    }

    private function write(string $text): void
    {
        $text = $this->masker->mask($text);
        if ('' === $text) {
            return;
        }
        $handle = @fopen($this->path, 'a');
        if (false === $handle) {
            throw new \RuntimeException('Cannot write the job log.');
        }
        try {
            fwrite($handle, $text);
            fflush($handle);
        } finally {
            fclose($handle);
        }
        @chmod($this->path, 0o640);
        $this->size += \strlen($text);

        $now = microtime(true);
        if ($now - $this->lastNotified >= $this->notifyEvery) {
            $this->lastNotified = $now;
            $this->feed->jobChanged($this->job, $this->size);
        }
    }
}
