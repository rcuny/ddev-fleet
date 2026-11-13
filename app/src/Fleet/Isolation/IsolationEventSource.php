<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

/**
 * FLE-327: a stream of Docker events, one JSON object per line.
 */
interface IsolationEventSource
{
    /**
     * Blocks and hands every chunk of event lines (one or more, newline separated) to $onLines as it arrives.
     *
     * @param \Closure(string): void $onLines
     *
     * @return bool true when the stream ended normally and may be opened again, false when Docker did not answer
     */
    public function consume(\Closure $onLines): bool;
}
