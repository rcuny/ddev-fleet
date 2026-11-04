<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * The changelog between the installed version and the latest release, kept in the cache item
 * `fleet.upgrade.changelog` so the Upgrade page can show it without a network call. Filled together with the release
 * catalog (daily timer, "Check now"); a failed fetch leaves the previous text in place and never throws.
 */
final readonly class ChangelogCache
{
    public const string CACHE_KEY = 'fleet.upgrade.changelog';

    /** Sections kept, newest first, and characters kept per section: the text is untrusted and shown as an excerpt. */
    public const int MAX_SECTIONS = 10;
    public const int MAX_BODY_CHARS = 4000;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ChangelogReader $reader,
        private ClockInterface $clock,
    ) {
    }

    /** The stored excerpt, or null when none was fetched (yet). Reads the cache only. */
    public function read(): ?ChangelogExcerpt
    {
        try {
            $data = $this->cache->getItem(self::CACHE_KEY)->get();
        } catch (\Throwable) {
            return null;
        }
        $tag = \is_array($data) && \is_string($data['tag'] ?? null) ? Version::tryParse($data['tag']) : null;
        if (null === $tag) {
            return null;
        }
        $sections = [];
        foreach (\is_array($data['sections'] ?? null) ? $data['sections'] : [] as $row) {
            $version = \is_array($row) && \is_string($row['version'] ?? null) ? Version::tryParse($row['version']) : null;
            if (null === $version) {
                continue;
            }
            $notes = array_values(array_filter(\is_array($row['notes'] ?? null) ? $row['notes'] : [], '\is_string'));
            $sections[] = new ChangelogSection(
                $version,
                \is_string($row['date'] ?? null) ? $row['date'] : null,
                \is_string($row['body'] ?? null) ? $row['body'] : '',
                new Rollout($notes),
            );
        }

        return new ChangelogExcerpt($tag, $sections);
    }

    /** Fetches the sections after $installed up to $latest and stores them. Returns false when the source failed. */
    public function refresh(?Version $installed, Version $latest): bool
    {
        try {
            $sections = $this->reader->between($installed, $latest);
        } catch (UpgradeSourceUnavailable) {
            return false;
        }

        $rows = [];
        foreach (\array_slice($sections, 0, self::MAX_SECTIONS) as $section) {
            $rows[] = [
                'version' => (string) $section->version,
                'date' => $section->date,
                'body' => mb_substr($section->body, 0, self::MAX_BODY_CHARS),
                'notes' => $section->rollout->notes,
            ];
        }
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $item->set(['tag' => (string) $latest, 'sections' => $rows, 'fetchedAt' => $this->clock->now()->format(\DATE_ATOM)]);
            $item->expiresAfter(null);
            $this->cache->save($item);
        } catch (\Throwable) {
            // An unwritable cache just leaves the page without an excerpt.
        }

        return true;
    }
}
