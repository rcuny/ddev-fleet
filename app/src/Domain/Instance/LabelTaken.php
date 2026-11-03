<?php

declare(strict_types=1);

namespace App\Domain\Instance;

/**
 * Another request created an instance with the same id first. The caller picks the next label and retries.
 */
final class LabelTaken extends \RuntimeException
{
    public function __construct(public readonly string $id, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('The instance "%s" already exists.', $id), 0, $previous);
    }
}
