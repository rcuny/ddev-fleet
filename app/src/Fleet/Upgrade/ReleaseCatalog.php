<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * The releases that exist, kept in the cache item `fleet.upgrade.catalog`. Pages and the footer only call state()
 * (no network); refresh() is for the daily timer and "Check now". A failed refresh keeps the previous releases and
 * records when it ran and why it failed, and never throws.
 */
final readonly class ReleaseCatalog
{
    public const string CACHE_KEY = 'fleet.upgrade.catalog';

    /** A listing younger than this counts as fresh (see isFresh()). */
    public const int TTL_SECONDS = 6 * 3600;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private GitRemoteTags $tags,
        private ClockInterface $clock,
    ) {
    }

    public function state(): CatalogState
    {
        try {
            $data = $this->cache->getItem(self::CACHE_KEY)->get();
        } catch (\Throwable) {
            return CatalogState::empty();
        }
        if (!\is_array($data)) {
            return CatalogState::empty();
        }

        $releases = [];
        foreach (\is_array($data['releases'] ?? null) ? $data['releases'] : [] as $row) {
            $release = \is_array($row) ? Release::fromArray($row) : null;
            if (null !== $release) {
                $releases[] = $release;
            }
        }
        $checkedAt = \is_string($data['checkedAt'] ?? null) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['checkedAt']) : false;
        $error = \is_string($data['error'] ?? null) && '' !== $data['error'] ? $data['error'] : null;

        $listedAt = \is_string($data['listedAt'] ?? null) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['listedAt']) : false;

        return new CatalogState($releases, false === $checkedAt ? null : $checkedAt, $error, false === $listedAt ? null : $listedAt);
    }

    public function isFresh(): bool
    {
        $state = $this->state();

        return null === $state->error
            && null !== $state->checkedAt
            && $this->clock->now()->getTimestamp() - $state->checkedAt->getTimestamp() < self::TTL_SECONDS;
    }

    /** Asks the release source and stores the answer. Returns the stored state. */
    public function refresh(): CatalogState
    {
        $now = $this->clock->now();
        try {
            $state = new CatalogState($this->tags->fetch(), $now, null, $now);
        } catch (UpgradeSourceUnavailable $e) {
            $previous = $this->state();
            $state = new CatalogState($previous->releases, $now, $e->getMessage(), $previous->listedAt ?? (null === $previous->error ? $previous->checkedAt : null));
        }

        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $item->set([
                'releases' => array_map(static fn (Release $r): array => $r->toArray(), $state->releases),
                'checkedAt' => $now->format(\DATE_ATOM),
                'listedAt' => $state->listedAt?->format(\DATE_ATOM),
                'error' => $state->error,
            ]);
            $item->expiresAfter(null);
            $this->cache->save($item);
        } catch (\Throwable) {
            // A cache that cannot be written leaves the answer in hand; the next page just sees the older state.
        }

        return $state;
    }
}
