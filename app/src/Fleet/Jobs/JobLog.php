<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Job\Job;

/**
 * The append-only log file of one job. Everything goes through the masker before it is written, and each write is
 * flushed so a reader sees it at once. The feed is told about growth at most every $notifyEvery seconds.
 *
 * With a TailAwareLogMasker the last (longest secret - 1) bytes are held back until more output arrives, because they
 * may be the start of a secret; finish() writes what is left. Call it when the job ends.
 */
final class JobLog
{
    private int $size;
    private string $held = '';
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

    /**
     * Writes the bytes held back for the masker. Safe to call more than once.
     */
    public function finish(): void
    {
        $this->append($this->masker->mask($this->held));
        $this->held = '';
    }

    private function write(string $text): void
    {
        if (!$this->masker instanceof TailAwareLogMasker) {
            $this->append($this->masker->mask($text));

            return;
        }

        // Mask the held tail together with the new text, then hold back the last bytes again: a secret that starts
        // before the cut is at most longestSecret() long, so it lies wholly in the buffer and has been masked.
        $buffer = $this->masker->mask($this->held.$text);
        $keep = max(0, $this->masker->longestSecret() - 1);
        $cut = max(0, \strlen($buffer) - $keep);
        $this->held = substr($buffer, $cut);
        $this->append(substr($buffer, 0, $cut));
    }

    private function append(string $text): void
    {
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
