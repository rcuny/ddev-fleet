<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the documentation tree: no front matter on any page, links that resolve, one mention of the retired Python
 * line, no private names, and every command named in at least one page besides the generated reference.
 */
final class DocsStructureTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @return list<string> docs/**\/*.md relative to the repository root
     */
    private static function pages(): array
    {
        return InstallDocsTest::markdownFiles();
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::root().'/'.$relative);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function docsPages(): iterable
    {
        foreach (self::pages() as $page) {
            yield $page => [$page];
        }
    }

    #[DataProvider('docsPages')]
    public function testNoPageStartsWithFrontmatter(string $page): void
    {
        // Product Markdown has no front matter (FLE-269). The kept files (.github issue templates, Claude skill files)
        // are not documentation pages and are not listed by pages().
        self::assertStringStartsNotWith('---', self::read($page), "$page must not start with a front matter block");
    }

    public function testReadmeAndContributorFilesHaveNoFrontmatter(): void
    {
        foreach (['README.md', 'CLAUDE.md', 'CONTRIBUTING.md', 'AI_POLICY.md'] as $file) {
            if (is_file(self::root().'/'.$file)) {
                self::assertStringStartsNotWith('---', self::read($file), "$file must not start with a front matter block");
            }
        }
    }

    public function testTheTreeIsNotEmpty(): void
    {
        self::assertGreaterThanOrEqual(20, \count(self::pages()));
    }

    public function testEveryRelativeLinkResolves(): void
    {
        $broken = [];
        foreach (['README.md', 'CONTRIBUTING.md', ...self::pages()] as $page) {
            // Links inside fenced code blocks are examples, not links.
            $text = (string) preg_replace('/^```.*?^```/ms', '', self::read($page));
            preg_match_all('/\]\(([^)\s]+)\)/', $text, $links);
            foreach ($links[1] as $target) {
                if (1 === preg_match('~^([a-z][a-z0-9+.-]*:|#)~i', $target)) {
                    continue;
                }
                $path = explode('#', $target, 2)[0];
                if (!file_exists(\dirname(self::root().'/'.$page).'/'.$path)) {
                    $broken[] = "$page -> $target";
                }
            }
        }

        self::assertSame([], $broken);
    }

    public function testTheRetiredPythonLineIsMentionedOnce(): void
    {
        $count = 0;
        $where = [];
        foreach (['README.md', ...self::pages()] as $page) {
            $n = (int) preg_match_all('/\blegacy\b/i', self::read($page));
            if ($n > 0) {
                $where[] = "$page ($n)";
            }
            $count += $n;
        }

        self::assertSame(1, $count, 'the word "legacy" belongs in one place only, the README: '.implode(', ', $where));
    }

    public function testNoPrivateNamesInTheDocumentation(): void
    {
        $problems = [];
        foreach (['README.md', 'CONTRIBUTING.md', ...self::pages()] as $page) {
            $text = self::read($page);
            foreach ([
                '/\bddev[1-5]\b/i' => 'a server name',
                '/\bosce\b/i' => 'a client project name',
                '/\bFLE-\d+/' => 'a ticket key',
            ] as $pattern => $what) {
                if (1 === preg_match($pattern, $text, $m)) {
                    $problems[] = "$page: $what ({$m[0]})";
                }
            }
            preg_match_all('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $text, $ips);
            foreach ($ips[0] as $ip) {
                // Documentation range, loopback, and the Docker address pool the upgrade page discusses.
                if (!str_starts_with($ip, '203.0.113.') && !str_starts_with($ip, '127.') && '10.64.0.0' !== $ip && '0.0.0.0' !== $ip) {
                    $problems[] = "$page: address $ip";
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testEveryPublicCommandIsMentionedOutsideTheReference(): void
    {
        $cli = self::read('docs/reference/01-cli.md');
        preg_match_all('/^### (fleet [a-z]+:[a-z-]+)\s*$/m', $cli, $headings);
        self::assertGreaterThan(50, \count($headings[1]), 'cli.md headings were not found');

        // Internal commands (workers and upgrade helpers) are marked on the line after their heading.
        $internal = [];
        foreach ($headings[1] as $heading) {
            if (1 === preg_match('/^### '.preg_quote($heading, '/').'\R+\*\(internal/m', $cli)) {
                $internal[] = $heading;
            }
        }

        $others = '';
        foreach (['README.md', ...array_filter(self::pages(), static fn (string $p): bool => 'docs/reference/01-cli.md' !== $p)] as $page) {
            $others .= "\n".self::read($page);
        }

        $missing = [];
        foreach (array_diff($headings[1], $internal) as $command) {
            if (1 !== preg_match('/'.preg_quote($command, '/').'(?![\w:-])/', $others)) {
                $missing[] = $command;
            }
        }

        self::assertSame([], $missing, 'commands that no page besides cli.md mentions');
    }
}
