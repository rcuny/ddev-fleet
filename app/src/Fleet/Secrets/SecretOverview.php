<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * The managed secrets of one scope, as a page or a command lists them. For a project, `scan` says which of them an
 * asset file uses; it is null for the fleet scope (a fleet-wide secret may serve any project).
 */
final readonly class SecretOverview
{
    /**
     * @param list<SecretSummary> $secrets
     */
    public function __construct(public array $secrets, public ?PlaceholderScan $scan)
    {
    }

    /**
     * Whether an asset file mentions the secret's placeholder; null when that is not known (fleet scope, or the
     * project has no asset files yet).
     */
    public function referenced(SecretSummary $secret): ?bool
    {
        if (null === $this->scan || !$this->scan->assetsDirExists || 0 === $this->scan->filesScanned) {
            return null;
        }

        return $this->scan->references(SecretName::token($secret->name));
    }

    /**
     * @return list<SecretSummary> the secrets whose placeholder no scanned file mentions
     */
    public function unreferenced(): array
    {
        return array_values(array_filter($this->secrets, fn (SecretSummary $secret): bool => false === $this->referenced($secret)));
    }
}
