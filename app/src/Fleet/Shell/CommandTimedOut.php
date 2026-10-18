<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

final class CommandTimedOut extends CommandRunnerException
{
    public function __construct(
        string $message,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
