<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * Reads the released sections of a Keep a Changelog file: `## [X.Y.Z] - date`. A heading that is not a version
 * (`## [Unreleased]`, a typo) ends the previous section and is skipped, so a malformed section never takes its
 * neighbours down. Inside a section, `#### Rollout` starts the rollout notes, which run to the next heading.
 * The text is untrusted: it is only split, never interpreted.
 */
final class ChangelogParser
{
    /**
     * @return list<ChangelogSection> in file order (newest first in a normal changelog)
     */
    public function parse(string $changelog): array
    {
        $sections = [];
        /** @var array{version: Version, date: ?string, body: list<string>, rollout: list<string>}|null $current */
        $current = null;
        $inRollout = false;

        $flush = static function () use (&$current, &$sections): void {
            if (null !== $current) {
                $sections[] = new ChangelogSection(
                    $current['version'],
                    $current['date'],
                    trim(implode("\n", $current['body'])),
                    new Rollout($current['rollout']),
                );
            }
            $current = null;
        };

        foreach (preg_split('/\R/', $changelog) ?: [] as $line) {
            if (1 === preg_match('/^## (.*)$/', $line, $m)) {
                $flush();
                $inRollout = false;
                if (1 === preg_match('/^\[v?([^\]\s]+)\](?:\s+-\s+(\d{4}-\d{2}-\d{2}))?\s*$/', $m[1], $h) && null !== ($version = Version::tryParse($h[1]))) {
                    $current = ['version' => $version, 'date' => $h[2] ?? null, 'body' => [], 'rollout' => []];
                }
                continue;
            }
            if (null === $current) {
                continue;
            }
            if (1 === preg_match('/^####\s+Rollout\s*$/i', $line)) {
                $inRollout = true;
                continue;
            }
            if (1 === preg_match('/^#{1,6}\s/', $line)) {
                $inRollout = false;
            }
            if (1 === preg_match('/^\[[^\]]+\]:\s/', $line)) {
                continue; // a reference link at the end of the file
            }
            if ($inRollout) {
                if (1 === preg_match('/^[-*]\s+(.+?)\s*$/', $line, $b)) {
                    $current['rollout'][] = $b[1];
                } elseif ('' !== trim($line) && [] !== $current['rollout']) {
                    $last = \count($current['rollout']) - 1;
                    $current['rollout'][$last] .= ' '.trim($line); // a wrapped bullet
                }
                continue;
            }
            $current['body'][] = $line;
        }
        $flush();

        return $sections;
    }
}
