<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ci/tag-guard.sh decides what a release tag may publish to the GitHub mirror (Bitbucket tag pipeline).
 * Each scenario builds a throw-away repository with local origin/main and origin/develop refs.
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

    public function testV0TagMustEqualOriginMain(): void
    {
        $main = $this->commit('release');
        $this->setRemote('main', $main);
        $this->setRemote('develop', $main);

        [$status, $out] = $this->guard('v0.7.0', $main);

        self::assertSame(0, $status);
        self::assertSame($main.':refs/heads/main refs/tags/v0.7.0', $out);
    }

    public function testV0TagOnAnotherCommitIsRejected(): void
    {
        $main = $this->commit('release');
        $other = $this->commit('later');
        $this->setRemote('main', $main);
        $this->setRemote('develop', $other);

        [$status, $out, $err] = $this->guard('v0.7.0', $other);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringStartsWith('ERROR:', $err);
    }

    public function testV1TagOnDevelopPrintsOnlyTheTagRef(): void
    {
        $base = $this->commit('base');
        $tagged = $this->commit('tagged');
        $tip = $this->commit('tip');
        $this->setRemote('main', $base);
        $this->setRemote('develop', $tip);

        [$status, $out] = $this->guard('v1.0.0', $tagged);
        self::assertSame(0, $status);
        self::assertSame('refs/tags/v1.0.0', $out);

        // The tip of develop itself is accepted too.
        [$status, $out] = $this->guard('v1.2.3', $tip);
        self::assertSame(0, $status);
        self::assertSame('refs/tags/v1.2.3', $out);
    }

    public function testV1TagNotReachableFromDevelopIsRejected(): void
    {
        $base = $this->commit('base');
        $this->setRemote('main', $base);
        $this->setRemote('develop', $this->commit('develop work'));
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
        $this->setRemote('main', $base);
        $this->setRemote('develop', $base);

        [$status, $out] = $this->guard('v1.0.0-rc1', $base);

        self::assertSame(0, $status);
        self::assertSame('refs/tags/v1.0.0-rc1', $out);
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
        yield 'v2 is unknown' => ['v2.0.0'];
        yield 'no prefix' => ['foo'];
        yield 'empty' => [''];
        yield 'bare v0' => ['v0'];
        yield 'malformed v0' => ['v0.x.1'];
    }

    #[DataProvider('rejectedTags')]
    public function testMalformedOrUnknownTagIsRejected(string $tag): void
    {
        $base = $this->commit('base');
        $this->setRemote('main', $base);
        $this->setRemote('develop', $base);

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
        $this->setRemote('main', $base);
        $this->setRemote('develop', $moved);

        // v1: the pipeline was triggered for $base, but the fetched tag now points at $moved (both are on develop).
        [$status, $out, $err] = $this->guard('v1.0.0', $base, $moved);
        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('moved after the trigger', $err);

        // v0: same with main.
        $this->setRemote('main', $base);
        [$status, $out, $err] = $this->guard('v0.7.0', $base, $moved);
        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringStartsWith('ERROR:', $err);
    }

    public function testTagThatWasNotFetchedIsRefused(): void
    {
        $base = $this->commit('base');
        $this->setRemote('main', $base);
        $this->setRemote('develop', $base);

        $process = new Process(['bash', self::script(), 'v1.0.0', $base], $this->repo);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', trim($process->getOutput()));
        self::assertStringContainsString('does not exist', $process->getErrorOutput());
    }

    public function testUnknownCommitAndWrongArgumentCountAreRejected(): void
    {
        $base = $this->commit('base');
        $this->setRemote('main', $base);
        $this->setRemote('develop', $base);

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

    private function setRemote(string $branch, string $sha): void
    {
        $this->git('update-ref', 'refs/remotes/origin/'.$branch, $sha);
    }
}
