<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ci/changelog-assemble.php builds the [Unreleased] text from changelog.d/ and cuts the release.
 */
final class ChangelogAssembleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/changelog-assemble-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/changelog.d', 0o777, true);
        file_put_contents($this->root.'/CHANGELOG.md', <<<'MD'
            # Changelog

            ## [Unreleased]

            ### Dependencies
            - Renovate group A

            ## [1.0.0] - 2026-01-01

            ### Added
            - first

            [Unreleased]: https://example.test/r/compare/v1.0.0...HEAD
            [1.0.0]: https://example.test/r/releases/tag/v1.0.0
            MD);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function assemble(array $args): array
    {
        $cmd = array_merge([\PHP_BINARY, \dirname(__DIR__, 3).'/ci/changelog-assemble.php', '--root='.$this->root], $args);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function testPreviewMergesFragmentsBySectionInOrder(): void
    {
        file_put_contents($this->root.'/changelog.d/FLE-2.md', "### Fixed\n- **FLE-2: b**\n\n### Added\n- **FLE-2: a**\n");
        file_put_contents($this->root.'/changelog.d/FLE-1.md', "### Added\n- **FLE-1: a**\n");
        file_put_contents($this->root.'/changelog.d/README.md', 'not a fragment');

        [$status, $out] = $this->assemble([]);

        self::assertSame(0, $status);
        self::assertSame(
            "### Added\n- **FLE-1: a**\n- **FLE-2: a**\n\n### Fixed\n- **FLE-2: b**\n\n### Dependencies\n- Renovate group A\n",
            $out,
        );
    }

    public function testReleaseWritesTheVersionDeletesFragmentsAndMovesTheCompareLinks(): void
    {
        file_put_contents($this->root.'/changelog.d/FLE-1.md', "### Added\n- **FLE-1: a**\n");

        [$status] = $this->assemble(['1.1.0', '2026-02-02']);

        self::assertSame(0, $status);
        $changelog = (string) file_get_contents($this->root.'/CHANGELOG.md');
        self::assertStringContainsString("## [Unreleased]\n\n## [1.1.0] - 2026-02-02\n\n### Added\n- **FLE-1: a**\n\n### Dependencies\n- Renovate group A\n\n## [1.0.0]", $changelog);
        self::assertStringContainsString("[Unreleased]: https://example.test/r/compare/v1.1.0...HEAD\n[1.1.0]: https://example.test/r/compare/v1.0.0...v1.1.0\n[1.0.0]:", $changelog);
        self::assertSame([], glob($this->root.'/changelog.d/*.md'));
    }

    public function testAFragmentWithoutASectionHeadingIsRefused(): void
    {
        file_put_contents($this->root.'/changelog.d/FLE-3.md', "- loose bullet\n");

        [$status, , $err] = $this->assemble([]);

        self::assertSame(1, $status);
        self::assertStringContainsString('FLE-3.md', $err);
    }

    public function testReleaseWithNothingToReleaseIsRefused(): void
    {
        file_put_contents($this->root.'/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n## [1.0.0] - 2026-01-01\n");

        [$status, , $err] = $this->assemble(['1.1.0']);

        self::assertSame(1, $status);
        self::assertStringContainsString('nothing to release', $err);
    }

    public function testTheRepositoryFragmentsAreWellFormed(): void
    {
        $root = \dirname(__DIR__, 3);
        // Right after a release changelog.d/ holds only its README; the pending text is then empty and the
        // released sections carry the entries.
        $fragments = glob($root.'/changelog.d/*.md') ?: [];
        self::assertContains($root.'/changelog.d/README.md', $fragments);
        self::assertNotSame('', trim(UnreleasedChangelog::text($root)));
    }
}
