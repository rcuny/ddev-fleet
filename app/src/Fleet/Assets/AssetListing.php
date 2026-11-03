<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

/**
 * The content of one folder: folders first, then files, each group by name. `truncated` is true when the folder held
 * more than AssetStore::MAX_ENTRIES items and only the first ones are shown.
 */
final readonly class AssetListing
{
    /**
     * @param list<string>     $segments the folder, relative to the project's directory (empty for the top)
     * @param list<AssetEntry> $entries
     */
    public function __construct(
        public array $segments,
        public array $entries,
        public bool $truncated,
    ) {
    }

    public function path(): string
    {
        return AssetPath::join($this->segments);
    }

    /**
     * The parent folder, or null at the top.
     */
    public function parent(): ?string
    {
        return [] === $this->segments ? null : AssetPath::join(\array_slice($this->segments, 0, -1));
    }

    /**
     * Breadcrumb: each folder on the way down, with its path.
     *
     * @return list<array{name: string, path: string}>
     */
    public function crumbs(): array
    {
        $crumbs = [];
        foreach ($this->segments as $i => $segment) {
            $crumbs[] = ['name' => $segment, 'path' => AssetPath::join(\array_slice($this->segments, 0, $i + 1))];
        }

        return $crumbs;
    }

    public function totalBytes(): int
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            $total += $entry->size ?? 0;
        }

        return $total;
    }
}
