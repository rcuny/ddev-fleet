<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

/**
 * A restore that did not finish. `rolledBack` says the previous databases are live again; when false the message
 * names the files that hold them.
 */
final class RestoreFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $rolledBack, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
