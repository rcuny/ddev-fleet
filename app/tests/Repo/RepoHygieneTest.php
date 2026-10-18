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

    /** New code only: the companion workspace. These are generic and not private, so they stay plain regexes. */
    private const COMPANION_PATTERNS = [
        '/\/var\/www\/html/i',
        '/\.claude\//i',
        '/ddev-fleet-dev/i',
    ];

    /**
     * Longest run of hyphen/dot separated segments that is hashed as one candidate ("a-b-c", "x.y.z" are 3).
     * Keeps the scan linear on long separator-heavy lines; every digest below is at most this long.
     */
    private const MAX_SPAN = 4;

    /**
     * SHA-256 digests of lower-cased owner terms (private project names, the owner's hosts and names). The terms
     * themselves must never appear in this public repository, not even here, so the scanner tokenises every file
     * (words "a-b", dotted hostnames "a.b.c", and every contiguous run of up to MAX_SPAN segments of those) and
     * compares the digests of the lower-cased tokens.
     *
     * To add a term:  printf %s 'the-term' | sha256sum   (lower-case, no trailing newline), append the 64 hex
     * characters below and bump the count and fingerprint in testOwnerDigestListIsPinned(). A short term is
     * guessable from its digest by brute force; this keeps the term out of grep and out of casual reading, not
     * out of a determined attacker's reach.
     */
    private const OWNER_DIGESTS = [
        '7aa45e4d796d29c305230781f42c477c30c6e9d7ffc5bd44386c8a342dcf95b5',
        'bf6e04f9d6d1d7dba9fc604d0b1692f33d7de9a34f0038018f51725fe74358de',
        '5c2a738adf36a837f3559998994137dd9c4877f5f579fa6086c10e1af2daf872',
        '039370110652afbf1b2d62b5de17634b98738b9b22d9c3e1deb08e9c34f81eb0',
        '9cf289950a21becc67e78c96896da33f1ad0f31d353713cf5e0baceec6dfb41f',
        '6b4270b87e4de99c2f8dd4d0f7600fd1a7e986a7b616d2d15ef044b9f0e6878a',
        '1ac8abc0e034c5035e23a47e38a1b876a9d261a35a36da79f49c3e1f59b595c5',
        '0e6a8e0b849ed9b064c5a25e1ee5592f427e3eb9d250e42069ce46147d00e8d4',
        '38c7c4f5f0db4f261217e6d300d12f9518be66533fb9021ab0db24d1f7f9c76f',
        '506fd18630356bc075f7c666d0a2c16959a57d9552b7c08cfb544b23f8607660',
        'e153204b4983e35c49df06826757be9515fa7e44b8971a1bb3062fc0b66def57',
        '27110efe04bd767b7c86124ff30282be21584979a564e08d18c4b1e476e00027',
    ];

    public function testNoPersonalLeakage(): void
    {
        $hits = $this->scan(self::root(), [''], [$this->regexMatcher($this->literals(self::PERSONAL_DENYLIST))], self::SKIP_PATHS);

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
        $hits = $this->scan(self::root(), $roots, $this->ownerMatchers(self::OWNER_DIGESTS), self::SKIP_PATHS);

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
            foreach ($this->ownerMatchers(self::OWNER_DIGESTS) as $matcher) {
                if (null !== $label = $matcher($line)) {
                    $hits[] = \sprintf('[Unreleased] line %d matched %s', $number + 1, $label);
                }
            }
        }

        self::assertSame([], $hits, "owner references in the [Unreleased] changelog entry:\n".implode("\n", $hits));
    }

    public function testOwnerDigestListIsPinned(): void
    {
        // The production list cannot be exercised with its real terms (they must not be written down), so pin it:
        // an accidental edit or removal of a digest changes the count or the fingerprint.
        $digests = self::OWNER_DIGESTS;
        foreach ($digests as $digest) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $digest);
        }
        self::assertCount(12, $digests);
        self::assertSame($digests, array_values(array_unique($digests)), 'duplicate digest');
        sort($digests);
        self::assertSame('d28faf6fdac9faed80ea03588a43aac98e505e5e050b3f6428811e2c74196f5b', hash('sha256', implode("\n", $digests)));
    }

    public function testDigestMatcherFlagsATermInAnyTokenShape(): void
    {
        // Synthetic words only: the mechanism is tested with a digest computed here, never with a real owner term.
        $matcher = $this->digestMatcher([hash('sha256', 'zorblax'), hash('sha256', 'quux-in-baz'), hash('sha256', 'zorblax.test')]);

        foreach ([
            'the zorblax project', 'open the Zorblax page', 'ZORBLAX', 'zorblax-main', 'zorblax--main', 'demo.zorblax.example',
            'https://zorblax.example/x', 'path/zorblax/x', 'zorblax_x', 'a quux-in-baz b', 'x.quux-in-baz.y', 'host zorblax.test.',
        ] as $line) {
            self::assertNotNull($matcher($line), $line);
        }
        foreach ([
            'a zorblaxes list, or xzorblax', 'zorblaxx and 1zorblax', 'quux in baz', 'quux-in', 'zorblax2', 'nothing here',
        ] as $line) {
            self::assertNull($matcher($line), $line);
        }
    }

    public function testDigestMatcherIgnoresLongSeparatorHeavyLinesQuickly(): void
    {
        $matcher = $this->digestMatcher([hash('sha256', 'zorblax')]);
        $line = str_repeat('a-b.', 20000);

        $started = microtime(true);
        self::assertNull($matcher($line));
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    public function testNestedDirectoriesNamedLikeSkippedOnesAreStillScanned(): void
    {
        $base = sys_get_temp_dir().'/hygiene-'.bin2hex(random_bytes(6));
        foreach (['app/templates/instances', 'app/src/var', 'app/var', 'app/vendor/x', 'instances'] as $dir) {
            mkdir($base.'/'.$dir, 0o700, true);
        }
        $leak = "host: demo.zorblax.example\n";
        foreach (['app/templates/instances/x.twig', 'app/src/var/y.php', 'app/var/z.log', 'app/vendor/x/v.php', 'instances/i.txt'] as $file) {
            file_put_contents($base.'/'.$file, $leak);
        }

        try {
            $hits = $this->scan($base, [''], $this->ownerMatchers([hash('sha256', 'zorblax')]), self::SKIP_PATHS);
        } finally {
            new \Symfony\Component\Process\Process(['rm', '-rf', $base])->mustRun();
        }

        $files = array_map(static fn (string $hit): string => explode(':', $hit)[0], $hits);
        sort($files);
        self::assertSame(['app/src/var/y.php', 'app/templates/instances/x.twig'], $files);
    }

    public function testCompanionPathsAreFlaggedAsPlainPatterns(): void
    {
        $matcher = $this->regexMatcher(self::COMPANION_PATTERNS);

        self::assertNotNull($matcher('cd /var/www/html && ls'));
        self::assertNotNull($matcher('see .claude/rules'));
        self::assertNotNull($matcher('remote ddev-fleet-dev.git'));
        self::assertNull($matcher('see /srv/www and /var/lib'));
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
     * The owner checks: the plain companion-path patterns plus the digest matcher.
     *
     * @param list<string> $digests SHA-256 hex digests of lower-cased terms
     *
     * @return list<callable(string): ?string> each returns a label when the line matches
     */
    private function ownerMatchers(array $digests): array
    {
        return [$this->regexMatcher(self::COMPANION_PATTERNS), $this->digestMatcher($digests)];
    }

    /**
     * @param list<string> $patterns PCRE patterns
     *
     * @return callable(string): ?string
     */
    private function regexMatcher(array $patterns): callable
    {
        return static function (string $line) use ($patterns): ?string {
            foreach ($patterns as $pattern) {
                if (1 === preg_match($pattern, $line)) {
                    return $pattern;
                }
            }

            return null;
        };
    }

    /**
     * @param list<string> $digests
     *
     * @return callable(string): ?string the label never contains the matched token (it would be the term)
     */
    private function digestMatcher(array $digests): callable
    {
        $wanted = array_flip($digests);
        /** @var array<string, bool> $memo token => is an owner term */
        $memo = [];

        return static function (string $line) use ($wanted, &$memo): ?string {
            if (1 > preg_match_all('/[a-z0-9]+(?:[.-]+[a-z0-9]+)*/', strtolower($line), $chunks)) {
                return null;
            }
            foreach ($chunks[0] as $chunk) {
                // [segment, separator, segment, ...]: try every contiguous run of up to MAX_SPAN segments.
                $parts = preg_split('/([.-]+)/', $chunk, -1, \PREG_SPLIT_DELIM_CAPTURE);
                \assert(\is_array($parts));
                for ($from = 0; $from < \count($parts); $from += 2) {
                    for ($to = $from; $to < \count($parts) && $to < $from + 2 * self::MAX_SPAN; $to += 2) {
                        $token = implode('', \array_slice($parts, $from, $to - $from + 1));
                        $memo[$token] ??= isset($wanted[hash('sha256', $token)]);
                        if ($memo[$token]) {
                            return 'an owner digest';
                        }
                    }
                }
            }

            return null;
        };
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
     * @param string                          $base      absolute directory the roots and skip paths are relative to
     * @param list<string>                    $roots     files or directories relative to $base ('' = all of it); each must exist and a directory must yield a file
     * @param list<callable(string): ?string> $matchers  each returns a label when the line matches
     * @param list<string>                    $skipPaths paths relative to $base that are skipped (exact, not by name)
     *
     * @return list<string> "relative/path:line: matched ..." (empty = clean)
     */
    private function scan(string $base, array $roots, array $matchers, array $skipPaths): array
    {
        $self = realpath(__FILE__);
        $hits = [];
        foreach ($roots as $root) {
            $absolute = '' === $root ? $base : $base.'/'.$root;
            self::assertFileExists($absolute, \sprintf('hygiene root "%s" does not exist, so it would be silently unscanned', $root));
            $visited = 0;
            foreach ($this->files($base, $absolute, $skipPaths) as $relative) {
                ++$visited;
                $file = $base.'/'.$relative;
                if (realpath($file) === $self) {
                    continue;
                }
                $text = @file_get_contents($file);
                if (false === $text || str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) {
                    continue;
                }
                foreach (explode("\n", $text) as $number => $line) {
                    foreach ($matchers as $matcher) {
                        if (null !== $label = $matcher($line)) {
                            // The line is not echoed: it may hold the very term the digest hides.
                            $hits[] = \sprintf('%s:%d: matched %s', $relative, $number + 1, $label);
                        }
                    }
                }
            }
            if (is_dir($absolute)) {
                self::assertGreaterThan(0, $visited, \sprintf('hygiene root "%s" yielded no file to scan', $root));
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
