<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\Check\TargetTagCheck;
use App\Fleet\Upgrade\Direction;
use App\Fleet\Upgrade\RepoUrl;

final class TargetTagCheckTest extends CheckTestCase
{
    private const string MAIN = 'dddddddddddddddddddddddddddddddddddddddd';

    private function check(RecordingCommandRunner $runner): TargetTagCheck
    {
        return new TargetTagCheck($runner, new RepoUrl(), '/srv/checkout');
    }

    private function mainTip(string $sha = self::MAIN): RecordingCommandRunner
    {
        return new RecordingCommandRunner()->expect(['git', 'ls-remote'], new CommandResult(0, $sha."\trefs/heads/main\n"));
    }

    public function testNoTargetIsNotThisChecksBusiness(): void
    {
        self::assertSame([], $this->check(new RecordingCommandRunner())->check(self::plan(to: null, direction: Direction::Unknown)));
    }

    public function testAnotherSeriesIsRefused(): void
    {
        $results = $this->check(new RecordingCommandRunner())->check(self::plan(to: 'v2.0.0'));

        self::assertSame([TargetTagCheck::SERIES], self::ids($results));
        self::assertFalse($results[0]->overridable);
    }

    public function testBelowTheFirstUpgradableReleaseIsRefused(): void
    {
        $results = $this->check(new RecordingCommandRunner())->check(self::plan(from: 'v1.2.0', to: 'v1.0.6', direction: Direction::Down));

        self::assertSame([TargetTagCheck::BELOW_MINIMUM], self::ids($results));
    }

    public function testTheTipOfMainIsOnMain(): void
    {
        self::assertSame([], $this->check($this->mainTip(self::SHA))->check(self::plan()));
    }

    public function testAnAncestorOfMainIsOnMain(): void
    {
        $runner = $this->mainTip()->expect(['git', '-C', '/srv/checkout', 'merge-base'], new CommandResult(0));

        self::assertSame([], $this->check($runner)->check(self::plan()));
    }

    public function testACommitOffMainBlocks(): void
    {
        $runner = $this->mainTip()->expect(['git', '-C', '/srv/checkout', 'merge-base'], new CommandResult(1));

        $results = $this->check($runner)->check(self::plan());

        self::assertSame([TargetTagCheck::NOT_ON_MAIN], self::ids($results));
        self::assertFalse($results[0]->overridable);
    }

    public function testMissingHistoryWarnsInsteadOfBlocking(): void
    {
        $runner = $this->mainTip()->expect(['git', '-C', '/srv/checkout', 'merge-base'], new CommandResult(128));

        $results = $this->check($runner)->check(self::plan());

        self::assertSame([TargetTagCheck::MAIN_UNVERIFIED], self::ids($results));
    }

    public function testAnUnreadableMainWarns(): void
    {
        $runner = new RecordingCommandRunner()->expect(['git', 'ls-remote'], new CommandResult(128));

        self::assertSame([TargetTagCheck::MAIN_UNVERIFIED], self::ids($this->check($runner)->check(self::plan())));
    }
}
