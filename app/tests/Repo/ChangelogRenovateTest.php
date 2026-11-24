<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * FLE-378: ci/changelog-assemble.php lists the Renovate PRs merged since the previous v1.* tag (git only).
 */
final class ChangelogRenovateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/changelog-renovate-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/changelog.d', 0o777, true);
        file_put_contents($this->root.'/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n## [1.0.0] - 2026-01-01\n\n### Added\n- first\n");
        $this->git('init', '-q', '-b', 'develop');
        $this->git('config', 'user.name', 'Test');
        $this->git('config', 'user.email', 'test@example.test');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'init');
        $this->git('tag', '-a', 'v1.0.0', '-m', 'v1.0.0');
        $this->merge('renovate/doctrine', 'Merged in renovate/doctrine (pull request #303)');
        $this->merge('renovate/twig', 'FLE-10 Merge Renovate PR #335 (twig ^3.31.0)');
        $this->merge('feature/FLE-1-x', 'FLE-1 Merge PR #340: a feature');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    private function git(string ...$args): void
    {
        $process = proc_open(array_values(['git', '-C', $this->root, ...$args]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), 'git '.implode(' ', $args));
    }

    private function merge(string $branch, string $subject): void
    {
        $this->git('switch', '-q', '-c', $branch);
        file_put_contents($this->root.'/'.str_replace('/', '-', $branch).'.txt', $branch);
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'work');
        $this->git('switch', '-q', 'develop');
        $this->git('merge', '-q', '--no-ff', '-m', $subject, $branch);
    }

    private function preview(): string
    {
        $cmd = [\PHP_BINARY, \dirname(__DIR__, 3).'/ci/changelog-assemble.php', '--root='.$this->root];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process));

        return $out;
    }

    public function testTwoRenovateMergesGiveTwoBulletsAndTheFeatureMergeNone(): void
    {
        file_put_contents($this->root.'/changelog.d/FLE-1.md', "### Added\n- **FLE-1: a feature**\n");
        $out = $this->preview();

        self::assertStringContainsString("### Dependencies\n", $out);
        self::assertSame(2, substr_count($out, '- Renovate:'));
        self::assertStringContainsString('(#303)', $out);
        self::assertStringContainsString('(#335)', $out);
        self::assertStringNotContainsString('#340', $out);
    }

    public function testAPrCitedByAFragmentIsNotDuplicated(): void
    {
        file_put_contents($this->root.'/changelog.d/renovate-2026-11-24.md', "### Dependencies\n- doctrine bumps (#303)\n");
        $out = $this->preview();

        self::assertSame(1, substr_count($out, '#303'));
        self::assertSame(1, substr_count($out, '- Renovate:'));
        self::assertStringContainsString('(#335)', $out);
    }

    public function testReleaseWritesTheBulletsAndMergesThemIntoOneSection(): void
    {
        file_put_contents($this->root.'/changelog.d/renovate-x.md', "### Dependencies\n- manual bump (#999)\n");
        $cmd = [\PHP_BINARY, \dirname(__DIR__, 3).'/ci/changelog-assemble.php', '--root='.$this->root, '1.1.0', '2026-11-24'];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process));

        $changelog = (string) file_get_contents($this->root.'/CHANGELOG.md');
        self::assertSame(1, substr_count($changelog, '### Dependencies'));
        self::assertStringContainsString('#999', $changelog);
        self::assertStringContainsString('(#303)', $changelog);
    }
}
