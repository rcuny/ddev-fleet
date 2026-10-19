<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * A text mentions placeholders that no secret or built-in value stands for. Lists the placeholders, never a value.
 */
final class UnresolvedTokens extends \DomainException
{
    /**
     * @param list<string> $tokens placeholder names without brackets
     */
    public function __construct(public readonly array $tokens)
    {
        parent::__construct('Unresolved placeholder(s): '.implode(', ', array_map(static fn (string $token): string => '[['.$token.']]', $tokens)).'.');
    }
}
