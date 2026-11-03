<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * The outcome of rendering a text: the text with every resolvable placeholder replaced, the placeholders that could
 * not be resolved, and the names of the secrets that were inserted (never their values).
 */
final readonly class TokenRendering
{
    /**
     * @param list<string> $unresolved placeholder names (without brackets), sorted, each once
     * @param list<string> $used       names of the managed secrets inserted, sorted, each once
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $text,
        public array $unresolved,
        public array $used,
    ) {
    }

    public function isComplete(): bool
    {
        return [] === $this->unresolved;
    }
}
