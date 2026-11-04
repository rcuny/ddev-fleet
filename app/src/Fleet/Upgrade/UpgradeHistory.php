<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * Reads /var/log/ddev-fleet/upgrade-history.jsonl (ruling R9). The file is root-owned and appended by the root helper
 * only (a restored database must not erase it, and the app must not be able to rewrite it); this class never writes.
 * A line that is not valid is skipped, a missing or unreadable file is an empty history.
 */
final readonly class UpgradeHistory
{
    /** A history older than this many lines is not read; the file is append-only and small. */
    private const int MAX_LINES = 5000;

    public function __construct(private string $file)
    {
    }

    /**
     * @return list<HistoryEntry> newest first
     */
    public function entries(int $limit = 50): array
    {
        if (!is_readable($this->file)) {
            return [];
        }
        $lines = @file($this->file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }
        $entries = [];
        foreach (\array_slice($lines, -self::MAX_LINES) as $line) {
            try {
                $data = json_decode($line, true, 4, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $entry = \is_array($data) ? HistoryEntry::fromArray($data) : null;
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return \array_slice(array_reverse($entries), 0, max(0, $limit));
    }

    public function path(): string
    {
        return $this->file;
    }
}
