<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

/**
 * A job step failed for a reason the user may read: the message is stored on the job and shown. Any other throwable is
 * logged and reported as an unexpected error, so its text never reaches a page.
 */
final class JobFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = 1)
    {
        parent::__construct($message);
    }
}
