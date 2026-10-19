<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * What a list may show about a secret: its name and when and by whom it was last set. Never the value.
 */
final readonly class SecretSummary
{
    public function __construct(
        public string $name,
        public string $scope,
        public string $scopeId,
        public string $updatedBy,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /** The placeholder that stands for this secret in an asset file, for example `[[jira-claude-token]]`. */
    public function placeholder(): string
    {
        return '[['.SecretName::token($this->name).']]';
    }
}
