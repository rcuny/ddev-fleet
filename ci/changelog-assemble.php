#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Assembles the changelog fragments in changelog.d/ into CHANGELOG.md.
 *
 *   php ci/changelog-assemble.php [--root=DIR]                    print the assembled Unreleased body (preview)
 *   php ci/changelog-assemble.php [--root=DIR] X.Y.Z [YYYY-MM-DD] cut the release: write "## [X.Y.Z] - date", empty
 *                                                                 "## [Unreleased]" on top, delete the fragments
 *
 * A fragment is changelog.d/<name>.md that starts with a "### <Section>" line (Added, Changed, ..., Dependencies)
 * and holds Keep a Changelog bullets. Fragments are read in file name order; text already under [Unreleased] in
 * CHANGELOG.md comes first. Sections are merged and written in the order of SECTIONS, unknown ones after them.
 * Exit codes: 0 done, 1 a fragment or the changelog is malformed.
 */
const SECTIONS = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security', 'Dependencies'];

/**
 * @param list<string> $texts the already trimmed texts to merge: each starts with a "### Section" line
 */
function mergeSections(array $texts): string
{
    /** @var array<string, list<string>> $sections */
    $sections = [];
    foreach ($texts as $text) {
        $current = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (1 === preg_match('/^### (.+?)\s*$/', $line, $m)) {
                $current = $m[1];
                $sections[$current] ??= [];
                continue;
            }
            if (null === $current) {
                if ('' !== trim($line)) {
                    throw new RuntimeException('text before the first "### Section" heading: '.trim($line));
                }
                continue;
            }
            $sections[$current][] = $line;
        }
    }

    $order = array_merge(
        array_values(array_intersect(SECTIONS, array_keys($sections))),
        array_values(array_diff(array_keys($sections), SECTIONS)),
    );
    $out = [];
    foreach ($order as $name) {
        $body = trim(implode("\n", $sections[$name]));
        if ('' !== $body) {
            $out[] = "### $name\n$body";
        }
    }

    return implode("\n\n", $out);
}

/**
 * @return list<string> fragment paths, sorted by file name
 */
function fragmentFiles(string $root): array
{
    $files = glob($root.'/changelog.d/*.md') ?: [];
    $files = array_values(array_filter($files, static fn (string $f): bool => 'README.md' !== basename($f)));
    sort($files, \SORT_STRING);

    return $files;
}

/**
 * @return array{before: string, body: string, after: string}
 */
function splitChangelog(string $changelog): array
{
    if (1 !== preg_match('/^## \[Unreleased\][ \t]*\R(.*?)(?=^## \[|\z)/ms', $changelog, $m, \PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('CHANGELOG.md has no "## [Unreleased]" section');
    }
    $start = $m[0][1];
    $end = $start + \strlen($m[0][0]);

    return ['before' => substr($changelog, 0, $start), 'body' => trim($m[1][0]), 'after' => substr($changelog, $end)];
}

function assembledBody(string $root): string
{
    $parts = splitChangelog((string) file_get_contents($root.'/CHANGELOG.md'));
    $texts = '' === $parts['body'] ? [] : [$parts['body']];
    foreach (fragmentFiles($root) as $file) {
        $text = trim((string) file_get_contents($file));
        if ('' === $text) {
            throw new RuntimeException(basename($file).' is empty');
        }
        try {
            mergeSections([$text]);
        } catch (RuntimeException $e) {
            throw new RuntimeException(basename($file).': '.$e->getMessage(), 0, $e);
        }
        $texts[] = $text;
    }

    return mergeSections($texts);
}

function release(string $root, string $version, string $date): void
{
    $body = assembledBody($root);
    if ('' === $body) {
        throw new RuntimeException('nothing to release: [Unreleased] and changelog.d/ are empty');
    }
    $path = $root.'/CHANGELOG.md';
    $parts = splitChangelog((string) file_get_contents($path));
    $after = $parts['after'];
    $unreleasedLink = '#^\[Unreleased\]: (\S+)/compare/(v[^.\s]+\.[^.\s]+\.[^.\s]+)\.\.\.HEAD$#m';
    if (1 === preg_match($unreleasedLink, $after, $m)) {
        $after = (string) preg_replace(
            $unreleasedLink,
            "[Unreleased]: $m[1]/compare/v$version...HEAD\n[$version]: $m[1]/compare/$m[2]...v$version",
            $after,
            1,
        );
    }
    file_put_contents($path, $parts['before']."## [Unreleased]\n\n## [$version] - $date\n\n$body\n\n".ltrim($after));
    foreach (fragmentFiles($root) as $file) {
        unlink($file);
    }
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $root = \dirname(__DIR__);
    $args = [];
    foreach (\array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--root=')) {
            $root = substr($arg, 7);
        } else {
            $args[] = $arg;
        }
    }

    try {
        if ([] === $args) {
            echo assembledBody($root), "\n";
        } else {
            if (1 !== preg_match('/^\d+\.\d+\.\d+$/', $args[0]) || \count($args) > 2) {
                throw new RuntimeException('usage: changelog-assemble.php [--root=DIR] [X.Y.Z [YYYY-MM-DD]]');
            }
            release($root, $args[0], $args[1] ?? date('Y-m-d'));
        }
    } catch (RuntimeException $e) {
        fwrite(\STDERR, 'changelog-assemble: '.$e->getMessage()."\n");
        exit(1);
    }
}
