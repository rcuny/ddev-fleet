<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The product is public: nothing from the owner's deployments, private projects or companion workspace may
 * land in it. Two scans share one walker (scan()); this file is excluded because it holds the denylists.
 */
final class RepoHygieneTest extends TestCase
{
    /**
     * Generated, vendored or local state, as exact paths relative to the repository root. A directory with the
     * same name anywhere else (app/templates/instances, app/src/Var, ...) is real code and is scanned.
     */
    private const SKIP_PATHS = [
        '.git', '.venv', '.pytest_cache', '.ruff_cache', '.ansible', '.mypy_cache', '.superpowers',
        'node_modules', 'instances', 'reports',
        'app/vendor', 'app/var', 'app/.phpunit.cache', 'app/public/assets', 'app/public/bundles', 'app/assets/vendor',
        'tests/playwright/node_modules', 'tests/playwright/test-results', 'tests/playwright/playwright-report',
        'tests/playwright/blob-report', 'tests/playwright/.run', 'tests/playwright/.auth',
        // Lock files carry third-party package metadata; the baseline names the live host the owner probes.
        'app/composer.lock', 'app/symfony.lock', 'tests/playwright/package-lock.json', 'ci/systemd-security-baseline.json',
    ];

    /** Compiled bytecode directories exist at any depth and hold nothing readable. */
    private const SKIP_ANYWHERE = ['__pycache__'];

    /** Whole repository: strings that identified the maintainer's own deployments (ported from the Python test). */
    private const PERSONAL_DENYLIST = [
        'personal-maintainer',
        'personal_maintainer',
        'ddev.personal.example',
        'fleet.personal.example',
        'contact@personal.example',
        'Kimsufi',
        'bitbucket.org/personal_maintainer',
    ];

    /** New code only: the companion workspace, the owner's hosts and the private projects of the owner's config repo. */
    private const OWNER_DENYLIST = [
        '/var/www/html',
        '.claude/',
        'ddev-fleet-dev',
        'fleet.pm',
        'renaud',
        'democratie-en-sante',
    ];

    /** Matched on word boundaries: short names that are also ordinary substrings of other words. */
    private const OWNER_WORDS = ['osce', 'pws', 'portfolio', 'ddev1', 'ddev2', 'ddev3', 'ddev4'];

    public function testNoPersonalLeakage(): void
    {
        $hits = $this->scan(self::root(), [''], $this->literals(self::PERSONAL_DENYLIST), self::SKIP_PATHS);

        self::assertSame([], $hits, "personal/deployment-specific data leaked:\n".implode("\n", $hits));
    }

    public function testNoCompanionOrOwnerReferencesInNewCode(): void
    {
        // Not scanned: ansible/ (reshaped in M0.02), CHANGELOG.md (release history keeps what it recorded; its
        // [Unreleased] section is scanned by testChangelogUnreleasedSectionIsClean), LICENSE (the copyright holder)
        // and SYSTEMD-SECURITY-REPORT.md (generated from the maintainer's live hosts). docs/v0 is scanned too.
        $roots = [
            'app', 'ci', 'bitbucket-pipelines.yml', 'renovate-config.json', 'tests/playwright', 'tests/pytest',
            'tests/README-tests.md', 'CLAUDE.md', 'README.md', 'CONTRIBUTING.md', 'docs',
            '.github', '.gitignore', 'demo', 'scripts',
        ];
        $hits = $this->scan(self::root(), $roots, $this->ownerPatterns(), self::SKIP_PATHS);

        self::assertSame([], $hits, "companion or owner references in public code:\n".implode("\n", $hits));
    }

    public function testChangelogUnreleasedSectionIsClean(): void
    {
        $changelog = (string) file_get_contents(self::root().'/CHANGELOG.md');
        if (1 !== preg_match('/^## \[Unreleased\]\R(.*?)(?=^## \[|\z)/ms', $changelog, $match)) {
            self::fail('CHANGELOG.md has no [Unreleased] section');
        }

        $hits = [];
        foreach (explode("\n", $match[1]) as $number => $line) {
            foreach ($this->ownerPatterns() as $pattern) {
                if (1 === preg_match($pattern, $line)) {
                    $hits[] = \sprintf('[Unreleased] line %d matched %s', $number + 1, $pattern);
                }
            }
        }

        self::assertSame([], $hits, "owner references in the [Unreleased] changelog entry:\n".implode("\n", $hits));
    }

    public function testNestedDirectoriesNamedLikeSkippedOnesAreStillScanned(): void
    {
        $base = sys_get_temp_dir().'/hygiene-'.bin2hex(random_bytes(6));
        foreach (['app/templates/instances', 'app/src/var', 'app/var', 'app/vendor/x', 'instances'] as $dir) {
            mkdir($base.'/'.$dir, 0o700, true);
        }
        $leak = "host: demo.fleet.pm\n";
        foreach (['app/templates/instances/x.twig', 'app/src/var/y.php', 'app/var/z.log', 'app/vendor/x/v.php', 'instances/i.txt'] as $file) {
            file_put_contents($base.'/'.$file, $leak);
        }

        try {
            $hits = $this->scan($base, [''], $this->ownerPatterns(), self::SKIP_PATHS);
        } finally {
            new \Symfony\Component\Process\Process(['rm', '-rf', $base])->mustRun();
        }

        $files = array_map(static fn (string $hit): string => explode(':', $hit)[0], $hits);
        sort($files);
        self::assertSame(['app/src/var/y.php', 'app/templates/instances/x.twig'], $files);
    }

    public function testOwnerWordsMatchWholeWordsOnly(): void
    {
        $patterns = $this->ownerPatterns();
        $matches = static function (string $line) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (1 === preg_match($pattern, $line)) {
                    return true;
                }
            }

            return false;
        };

        self::assertTrue($matches('the osce project'));
        self::assertTrue($matches('open the Portfolio page'));
        self::assertFalse($matches('a portfolios list, or prosecute'));
        self::assertFalse($matches('ddev10 and ddev5'));
    }

    public function testPythonAppIsGone(): void
    {
        foreach (['src/fleet', 'pyproject.toml', 'fleet.yml.dist', 'bootstrap.sh'] as $path) {
            self::assertFileDoesNotExist(self::root().'/'.$path, $path.' belongs to the Python app (0.x lives on main)');
            self::assertDirectoryDoesNotExist(self::root().'/'.$path);
        }
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @return list<string>
     */
    private function ownerPatterns(): array
    {
        return [...$this->literals(self::OWNER_DENYLIST), ...array_map(
            static fn (string $word): string => '/\b'.preg_quote($word, '/').'\b/i',
            self::OWNER_WORDS,
        )];
    }

    /**
     * @param list<string> $needles
     *
     * @return list<string> case-insensitive literal patterns
     */
    private function literals(array $needles): array
    {
        return array_map(static fn (string $needle): string => '/'.preg_quote($needle, '/').'/i', $needles);
    }

    /**
     * Every text file under the given files/directories whose lines match any pattern.
     *
     * @param string       $base      absolute directory the roots and skip paths are relative to
     * @param list<string> $roots     files or directories relative to $base ('' = all of it); missing ones are skipped
     * @param list<string> $patterns  PCRE patterns
     * @param list<string> $skipPaths paths relative to $base that are skipped (exact, not by name)
     *
     * @return list<string> "relative/path:line: matched ..." (empty = clean)
     */
    private function scan(string $base, array $roots, array $patterns, array $skipPaths): array
    {
        $self = realpath(__FILE__);
        $hits = [];
        foreach ($roots as $root) {
            $absolute = '' === $root ? $base : $base.'/'.$root;
            if (!file_exists($absolute)) {
                continue;
            }
            foreach ($this->files($base, $absolute, $skipPaths) as $relative) {
                $file = $base.'/'.$relative;
                if (realpath($file) === $self) {
                    continue;
                }
                $text = @file_get_contents($file);
                if (false === $text || str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) {
                    continue;
                }
                foreach (explode("\n", $text) as $number => $line) {
                    foreach ($patterns as $pattern) {
                        if (1 === preg_match($pattern, $line)) {
                            $hits[] = \sprintf('%s:%d: matched %s: %s', $relative, $number + 1, $pattern, trim($line));
                        }
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $skipPaths
     *
     * @return \Generator<string> paths relative to $base
     */
    private function files(string $base, string $start, array $skipPaths): \Generator
    {
        $relative = static fn (string $path): string => ltrim(substr($path, \strlen($base)), '/');
        $skipped = static fn (\SplFileInfo $entry): bool => \in_array($relative($entry->getPathname()), $skipPaths, true)
            || ($entry->isDir() && \in_array($entry->getFilename(), self::SKIP_ANYWHERE, true));

        if (is_file($start)) {
            if (!$skipped(new \SplFileInfo($start))) {
                yield $relative($start);
            }

            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $entry): bool => !$skipped($entry),
        ));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                yield $relative($entry->getPathname());
            }
        }
    }
}
