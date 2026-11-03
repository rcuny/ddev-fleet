<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandTimedOut;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\GitRemoteTags;
use App\Fleet\Upgrade\RepoUrl;
use App\Fleet\Upgrade\UpgradeSourceUnavailable;
use PHPUnit\Framework\TestCase;

final class GitRemoteTagsTest extends TestCase
{
    private const string OUTPUT = <<<'TXT'
        1111111111111111111111111111111111111111	refs/tags/v1.0.6
        2222222222222222222222222222222222222222	refs/tags/v1.0.6^{}
        3333333333333333333333333333333333333333	refs/tags/v1.10.0
        4444444444444444444444444444444444444444	refs/tags/v1.10.0^{}
        5555555555555555555555555555555555555555	refs/tags/v1.2.0
        6666666666666666666666666666666666666666	refs/tags/v1.2.0^{}
        7777777777777777777777777777777777777777	refs/tags/v1.3.0-rc.1
        8888888888888888888888888888888888888888	refs/tags/v1.3.0-rc.1^{}
        9999999999999999999999999999999999999999	refs/tags/v1.5.0
        aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa	refs/tags/v1.junk
        bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb	refs/tags/v1.junk^{}
        cccccccccccccccccccccccccccccccccccccccc	refs/tags/v0.20.2
        dddddddddddddddddddddddddddddddddddddddd	refs/tags/v0.20.2^{}
        TXT;

    public function testParseKeepsAnnotatedV1TagsSemverSortedWithTheirCommit(): void
    {
        $releases = GitRemoteTags::parse(self::OUTPUT);

        self::assertSame(['v1.10.0', 'v1.3.0-rc.1', 'v1.2.0', 'v1.0.6'], array_map(static fn ($r): string => $r->tag(), $releases));
        self::assertSame(str_repeat('4', 40), $releases[0]->sha, 'the peeled commit, not the tag object');
    }

    public function testParseIgnoresLightweightTagsJunkAndV0(): void
    {
        $tags = array_map(static fn ($r): string => $r->tag(), GitRemoteTags::parse(self::OUTPUT));

        self::assertNotContains('v1.5.0', $tags, 'lightweight (no peeled line)');
        self::assertNotContains('v0.20.2', $tags);
        self::assertSame([], GitRemoteTags::parse("garbage\n\n"));
    }

    public function testFetchRunsLsRemoteWithoutAShellOrAPrompt(): void
    {
        $runner = new RecordingCommandRunner();
        $runner->expect(['git', 'ls-remote'], new CommandResult(0, self::OUTPUT));

        new GitRemoteTags($runner, new RepoUrl())->fetch();

        $call = $runner->calls()[0];
        self::assertSame(['git', 'ls-remote', '--tags', 'https://github.com/rcuny/ddev-fleet.git', 'v1.*'], $call->argv);
        self::assertSame('0', $call->env['GIT_TERMINAL_PROMPT']);
        self::assertSame(15.0, $call->timeout);
    }

    public function testFailuresBecomeUpgradeSourceUnavailable(): void
    {
        $failing = new RecordingCommandRunner();
        $failing->expect(['git', 'ls-remote'], new CommandResult(128, '', "fatal: unable to access: Could not resolve host\nmore"));
        try {
            new GitRemoteTags($failing, new RepoUrl())->fetch();
            self::fail('expected an exception');
        } catch (UpgradeSourceUnavailable $e) {
            self::assertSame('git ls-remote exited 128: fatal: unable to access: Could not resolve host', $e->getMessage());
        }

        $slow = new RecordingCommandRunner();
        $slow->expect(['git', 'ls-remote'], new CommandResult(0), static function (): never {
            throw new CommandTimedOut('git timed out');
        });
        $this->expectException(UpgradeSourceUnavailable::class);
        $this->expectExceptionMessage('timed out');
        new GitRemoteTags($slow, new RepoUrl())->fetch();
    }
}
