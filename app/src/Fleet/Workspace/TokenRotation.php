<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * The outcome of a token rotation: when it happened and the running instances that will pick the token up.
 */
final readonly class TokenRotation
{
    /**
     * @param list<string> $instances ids of the running instances
     */
    public function __construct(public \DateTimeImmutable $rotatedAt, public array $instances)
    {
    }
}
