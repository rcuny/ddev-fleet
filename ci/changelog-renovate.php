<?php

declare(strict_types=1);

/**
 * Builds the "### Dependencies" bullets for the Renovate PRs merged since the previous v1.* tag, from git only
 * (no API). Used by ci/changelog-assemble.php; a root that is not a git repository yields nothing.
 *
 * A Renovate merge is a first-parent merge commit M of HEAD whose merged side (M^1..M^2) holds at least one commit
 * authored by Renovate (author name or e-mail starting with "renovate", e.g. "renovate-ddev4" or
 * renovate@whitesourcesoftware.com). The subject only gives the PR number ("pull request #N", "PR #N", "Renovate PR #N")
 * and the title. PRs of one family (same branch name, or same first word of the title) share one bullet. A PR already cited as "#N" in the given texts is skipped.
 */

/**
 * @param list<string> $citedTexts changelog texts (fragments, [Unreleased]) whose "#N" references must not be repeated
 */
function renovateDependenciesSection(string $root, array $citedTexts): string
{
    $cited = [];
    foreach ($citedTexts as $text) {
        if (preg_match_all('/#(\d+)/', $text, $m)) {
            foreach ($m[1] as $n) {
                $cited[(int) $n] = true;
            }
        }
    }

    /** @var array<string, array{title: string, prs: list<int>}> $families */
    $families = [];
    foreach (renovateMergeSubjects($root) as $subject) {
        if (1 !== preg_match('/(?:pull request|PR) #(\d+)/i', $subject, $pr)) {
            continue;
        }
        $number = (int) $pr[1];
        if (isset($cited[$number])) {
            continue;
        }
        if (1 === preg_match('#renovate/([\w.\-/]+)#i', $subject, $b)) {
            $title = $b[1];
            $key = strtolower($b[1]);
        } else {
            $title = 1 === preg_match('/\(([^)]+)\)\s*$/', $subject, $t) ? $t[1] : (1 === preg_match('/PR #\d+\b:?\s*(\S.*)$/i', $subject, $t) ? $t[1] : 'dependency update');
            $title = preg_replace('/^update dependency\s+/i', '', $title) ?? $title;
            $key = strtolower(explode(' ', trim($title))[0]);
        }
        $families[$key]['title'] ??= $title;
        $families[$key]['prs'][] = $number;
        $cited[$number] = true;
    }
    if ([] === $families) {
        return '';
    }

    $lines = ['### Dependencies'];
    foreach ($families as $family) {
        sort($family['prs']);
        $refs = implode(', ', array_map(static fn (int $n): string => '#'.$n, $family['prs']));
        $lines[] = \sprintf('- Renovate: %s (%s)', $family['title'], $refs);
    }

    return implode("\n", $lines);
}

/**
 * @return list<string> subjects of the first-parent merges since the highest v1.* tag (by version) whose merged side
 *                      holds a Renovate-authored commit; newest first
 */
function renovateMergeSubjects(string $root): array
{
    if (!is_dir($root.'/.git') && !is_file($root.'/.git')) {
        return [];
    }
    $git = static function (array $args) use ($root): ?string {
        $process = proc_open(array_merge(['git', '-C', $root], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return 0 === proc_close($process) ? $out : null;
    };
    // A release tag sits on main and contains everything released, so the range is simply tag..HEAD, whether or not the
    // release was already back-merged into develop.
    $tag = trim(explode("\n", trim((string) $git(['tag', '--list', 'v1.*', '--sort=-v:refname'])))[0]);
    $range = '' === $tag ? 'HEAD' : $tag.'..HEAD';
    $log = $git(['log', '--first-parent', '--merges', '--format=%H%x09%s', $range]);

    $subjects = [];
    foreach (explode("\n", (string) $log) as $line) {
        [$sha, $subject] = array_pad(explode("\t", $line, 2), 2, '');
        if ('' === $sha || 1 === preg_match("#^Merge branch '(?:release|hotfix)/#", $subject)) {
            continue;
        }
        $authors = $git(['log', '--format=%an <%ae>', $sha.'^1..'.$sha.'^2']);
        foreach (explode("\n", (string) $authors) as $author) {
            if (1 === preg_match('/^renovate|<renovate/i', $author)) {
                $subjects[] = $subject;
                break;
            }
        }
    }

    return $subjects;
}
