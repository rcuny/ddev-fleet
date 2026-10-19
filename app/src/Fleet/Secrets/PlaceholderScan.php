<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * Which `[[token]]` placeholders the text files of a project's assets directory mention.
 */
final readonly class PlaceholderScan
{
    /**
     * @param array<string, true> $tokens   placeholder names (without brackets) found
     * @param bool                $complete false when the file limit stopped the scan early
     */
    public function __construct(
        public bool $assetsDirExists,
        public int $filesScanned,
        private array $tokens,
        public bool $complete = true,
    ) {
    }

    public function references(string $token): bool
    {
        return isset($this->tokens[$token]);
    }
}
