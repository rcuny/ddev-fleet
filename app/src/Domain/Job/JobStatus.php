<?php

declare(strict_types=1);

namespace App\Domain\Job;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Interrupted = 'interrupted';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            self::Succeeded, self::Failed, self::Interrupted => true,
        };
    }
}
