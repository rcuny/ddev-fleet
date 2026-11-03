<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ci/tag-guard.sh decides what a release tag may publish to the GitHub mirror (Bitbucket tag pipeline).
 * Each scenario builds a throw-away repository with local origin/main, origin/develop and origin/legacy refs.
 */
final class TagGuardTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir().'/tag-guard-'.bin2hex(random_bytes(6));
        mkdir($this->repo, 0o700, true);
        $this->git('init', '-q', '-b', 'work');
    }

    protected function tearDown(): void
    {
        if ('' !== $this->repo && is_dir($this->repo)) {
            new Process(['rm', '-rf', $this->repo])->mustRun();
        }
    }

    public function testScriptIsExecutableAndParses(): void
    {
        $script = self::script();
        self::assertFileExists($script);
        self::assertTrue(is_executable($script), 'ci/tag-guard.sh must be executable');
        self::assertSame(0, new Process(['bash', '-n', $script])->run());
    }

    public function testV0TagMustBeTheTipOfOriginLegacy(): void
    {
        $legacy = $this->commit('release');
        $this->setRemotes(main: $legacy, develop: $legacy, legacy: $legacy);

        [$status, $out] = $this->guard('v0.20.3', $legacy);

        self::assertSame(0, $status);
        self::assertSame($legacy.':refs/heads/legacy refs/tags/v0.20.3', $out);
    }

    public function testV0TagThatIsNotTheLegacyTipIsRejected(): void
    {
        $old = $this->commit('old release');
        $tip = $this->commit('later');
        $this->setRemotes(main: $tip, develop: $tip, legacy: $tip);

        [$status, $out, $err] = $this->guard('v0.20.2', $old);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('origin/legacy', $err);
    }

    public function testV1TagOnTheTipOfMainMovesMainAndPushesTheTag(): void
    {
        $base = $this->commit('base');
        $tip = $this->commit('release');
        $this->setRemotes(main: $tip, develop: $tip, legacy: $base);

        [$status, $out] = $this->guard('v1.1.0', $tip);

        self::assertSame(0, $status);
        self::assertSame($tip.':refs/heads/main refs/tags/v1.1.0', $out);
    }

    public function testV1TagThatIsAnAncestorOfMainPushesOnlyTheTag(): void
    {
        $base = $this->commit('base');
        $tagged = $this->commit('tagged on develop before the cut-over');
        $tip = $this->commit('release');
        $this->setRemotes(main: $tip, develop: $tip, legacy: $base);

        [$status, $out] = $this->guard('v1.0.6', $tagged);

        self::assertSame(0, $status);
        self::assertSame('refs/tags/v1.0.6', $out);
    }

    public function testV1TagOnlyOnDevelopIsRejected(): void
    {
        $base = $this->commit('base');
        $ahead = $this->commit('develop work');
        $this->setRemotes(main: $base, develop: $ahead, legacy: $base);

        [$status, $out, $err] = $this->guard('v1.1.0', $ahead);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('origin/main', $err);
    }

    public function testV1TagNotReachableFromMainIsRejected(): void
    {
        $base = $this->commit('base');
        $this->setRemotes(main: $this->commit('main work'), develop: $base, legacy: $base);
        $this->git('checkout', '-q', '-b', 'stray', $base);
        $stray = $this->commit('stray');

        [$status, $out, $err] = $this->guard('v1.0.0', $stray);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringStartsWith('ERROR:', $err);
    }

    public function testV1PrereleaseTagAccepted(): void
    {
        $base = $this->commit('base');
        $this->setRemotes(main: $base, develop: $base, legacy: $base);

        [$status, $out] = $this->guard('v1.2.0-rc1', $base);

        self::assertSame(0, $status);
        self::assertSame($base.':refs/heads/main refs/tags/v1.2.0-rc1', $out);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedTags(): iterable
    {
        yield 'two components' => ['v1.0'];
        yield 'non numeric minor' => ['v1.x.0'];
        yield 'empty prerelease' => ['v1.0.0-'];
        yield 'double dot in prerelease' => ['v1.0.0-rc..1'];
        yield 'prerelease ending in .lock' => ['v1.0.0-x.lock'];
        yield 'shell metacharacter' => ['v1.0.0;id'];
        yield 'space' => ['v1.0.0 x'];
        yield 'v0 without patch' => ['v0.20'];
        yield 'v0 prerelease dash only' => ['v0.20.3-'];
        yield 'no prefix' => ['foo'];
        yield 'empty' => [''];
        yield 'bare v0' => ['v0'];
        yield 'malformed v0' => ['v0.x.1'];
    }

    #[DataProvider('rejectedTags')]
    public function testMalformedOrUnknownTagIsRejected(string $tag): void
    {
        $base = $this->commit('base');
        $this->setRemotes(main: $base, develop: $base, legacy: $base);

        [$status, $out, $err] = $this->guard($tag, $base);

        self::assertSame(1, $status, $tag);
        self::assertSame('', $out);
        self::assertStringStartsWith('ERROR:', $err);
    }

    public function testMissingRemoteRefsAreReportedNotCrashed(): void
    {
        $base = $this->commit('base');

        [$status, , $err] = $this->guard('v1.0.0', $base);
        self::assertSame(1, $status);
        self::assertStringStartsWith('ERROR:', $err);

        [$status, , $err] = $this->guard('v0.7.0', $base);
        self::assertSame(1, $status);
        self::assertStringStartsWith('ERROR:', $err);
    }

    public function testTagMovedAfterTheTriggerIsRefused(): void
    {
        $base = $this->commit('base');
        $moved = $this->commit('moved to');
        $this->setRemotes(main: $moved, develop: $moved, legacy: $base);

        // v1: the pipeline was triggered for $base, but the fetched tag now points at $moved (both are on main).
        [$status, $out, $err] = $this->guard('v1.0.0', $base, $moved);
        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('moved after the trigger', $err);

        // v0: same with legacy.
        [$status, $out, $err] = $this->guard('v0.7.0', $base, $moved);
        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringStartsWith('ERROR:', $err);
    }

    public function testTagThatWasNotFetchedIsRefused(): void
    {
        $base = $this->commit('base');
        $this->setRemotes(main: $base, develop: $base, legacy: $base);

        $process = new Process(['bash', self::script(), 'v1.0.0', $base], $this->repo);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', trim($process->getOutput()));
        self::assertStringContainsString('does not exist', $process->getErrorOutput());
    }

    public function testUnknownCommitAndWrongArgumentCountAreRejected(): void
    {
        $base = $this->commit('base');
        $this->setRemotes(main: $base, develop: $base, legacy: $base);

        [$status, , $err] = $this->guard('v1.0.0', str_repeat('0', 40));
        self::assertSame(1, $status);
        self::assertStringStartsWith('ERROR:', $err);

        $process = new Process(['bash', self::script(), 'v1.0.0'], $this->repo);
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertStringStartsWith('ERROR:', $process->getErrorOutput());
    }

    private static function script(): string
    {
        return \dirname(__DIR__, 3).'/ci/tag-guard.sh';
    }

    /**
     * @return array{int, string, string} exit code, trimmed stdout, trimmed stderr
     */
    private function guard(string $tag, string $commit, ?string $tagAt = null): array
    {
        // The pipeline fetches the tag before calling the guard: by default it points at the triggering commit.
        // Names git refuses (spaces, ';') cannot exist as tags, the guard rejects those by name first.
        if ('' !== $tag && 1 === preg_match('/^[0-9A-Za-z.\-]+$/', $tag) && !str_ends_with($tag, '.lock') && !str_contains($tag, '..')) {
            $target = $tagAt ?? (1 === preg_match('/^[0-9a-f]{40}$/', $commit) ? $commit : null);
            if (null !== $target) {
                new Process(['git', 'tag', '-f', $tag, $target], $this->repo)->run();
            }
        }

        $process = new Process(['bash', self::script(), $tag, $commit], $this->repo);
        $process->run();

        return [(int) $process->getExitCode(), trim($process->getOutput()), trim($process->getErrorOutput())];
    }

    private function git(string ...$args): string
    {
        $process = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', ...$args], $this->repo);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function commit(string $message): string
    {
        $this->git('commit', '-q', '--allow-empty', '-m', $message);

        return $this->git('rev-parse', 'HEAD');
    }

    private function setRemotes(string $main, string $develop, string $legacy): void
    {
        foreach (['main' => $main, 'develop' => $develop, 'legacy' => $legacy] as $branch => $sha) {
            $this->git('update-ref', 'refs/remotes/origin/'.$branch, $sha);
        }
    }
}
