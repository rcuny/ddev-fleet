<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * What the release check knows: the releases of the last good listing, when the last attempt ran, and why it failed
 * (null when it worked). After a failure the releases are the stale ones, never dropped.
 */
final readonly class CatalogState
{
    /**
     * @param list<Release> $releases all annotated v1.* tags, newest first (pre-releases included)
     */
    public function __construct(
        public array $releases,
        public ?\DateTimeImmutable $checkedAt,
        public ?string $error,
    ) {
    }

    public static function empty(): self
    {
        return new self([], null, null);
    }

    /**
     * @return list<Release> the stable releases (no pre-release suffix), newest first
     */
    public function stable(): array
    {
        return array_values(array_filter($this->releases, static fn (Release $r): bool => !$r->version->isPreRelease()));
    }

    public function latest(): ?Release
    {
        return $this->stable()[0] ?? null;
    }

    public function find(Version $version): ?Release
    {
        foreach ($this->releases as $release) {
            if (0 === $release->version->compare($version)) {
                return $release;
            }
        }

        return null;
    }

    public function hasFailed(): bool
    {
        return null !== $this->error;
    }
}
