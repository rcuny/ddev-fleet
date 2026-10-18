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
    /** Directories that are generated, vendored or local state, at any depth. */
    private const SKIP_DIRS = [
        '.git', 'node_modules', 'vendor', 'var', '.venv', '__pycache__', 'instances',
        '.pytest_cache', '.ruff_cache', '.ansible', '.mypy_cache', '.phpunit.cache', '.php-cs-fixer.cache',
        'test-results', 'playwright-report', '.run', '.superpowers',
    ];

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
        $hits = $this->scan([self::root()], $this->literals(self::PERSONAL_DENYLIST), self::SKIP_DIRS);

        self::assertSame([], $hits, "personal/deployment-specific data leaked:\n".implode("\n", $hits));
    }

    public function testNoCompanionOrOwnerReferencesInNewCode(): void
    {
        // Root documentation (CLAUDE.md, README.md, CONTRIBUTING.md, docs/*.md, tests/README-tests.md) joins this list
        // in the commit that rewrites it (M0.01 Task 12); until then it still describes the Python app.
        $roots = [];
        foreach ([
            'app/src', 'app/templates', 'app/config', 'app/assets/controllers', 'app/tests',
            'tests/playwright',
            'docs/development.md', 'docs/README.md',
        ] as $relative) {
            if (file_exists(self::root().'/'.$relative)) {
                $roots[] = self::root().'/'.$relative;
            }
        }
        self::assertContains(self::root().'/app/src', $roots, 'app/src must be scanned');

        $patterns = [...$this->literals(self::OWNER_DENYLIST), ...array_map(
            static fn (string $word): string => '/\b'.preg_quote($word, '/').'\b/i',
            self::OWNER_WORDS,
        )];
        $hits = $this->scan($roots, $patterns, self::SKIP_DIRS);

        self::assertSame([], $hits, "companion or owner references in public code:\n".implode("\n", $hits));
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
     * @param list<string> $roots    absolute files or directories; missing ones are skipped
     * @param list<string> $patterns PCRE patterns
     * @param list<string> $skipDirs directory names skipped at any depth
     *
     * @return list<string> "relative/path:line: matched ..." (empty = clean)
     */
    private function scan(array $roots, array $patterns, array $skipDirs): array
    {
        $self = realpath(__FILE__);
        $hits = [];
        foreach ($roots as $root) {
            if (!file_exists($root)) {
                continue;
            }
            foreach ($this->files($root, $skipDirs) as $file) {
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
                            $hits[] = \sprintf('%s:%d: matched %s: %s', substr($file, \strlen(self::root()) + 1), $number + 1, $pattern, trim($line));
                        }
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $skipDirs
     *
     * @return \Generator<string>
     */
    private function files(string $root, array $skipDirs): \Generator
    {
        if (is_file($root)) {
            yield $root;

            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $entry): bool => !($entry->isDir() && \in_array($entry->getFilename(), $skipDirs, true)),
        ));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                yield $entry->getPathname();
            }
        }
    }
}
