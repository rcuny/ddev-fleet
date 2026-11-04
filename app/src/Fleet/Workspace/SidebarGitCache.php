<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * Remembers the live branch and short HEAD of each checkout for a while (five minutes), so a sidebar that redraws every few
 * seconds does not run git for every instance on every tick. One per sidebar process.
 */
final class SidebarGitCache
{
    public const int TTL_SECONDS = 300;

    /** @var array<string, array{at: int, value: array{branch: string, head: string}}> */
    private array $entries = [];

    /**
     * @param callable(): array{branch: string, head: string} $load
     *
     * @return array{branch: string, head: string}
     */
    public function get(string $id, int $now, callable $load): array
    {
        $entry = $this->entries[$id] ?? null;
        if (null === $entry || $now - $entry['at'] >= self::TTL_SECONDS) {
            $entry = ['at' => $now, 'value' => $load()];
            $this->entries[$id] = $entry;
        }

        return $entry['value'];
    }
}
