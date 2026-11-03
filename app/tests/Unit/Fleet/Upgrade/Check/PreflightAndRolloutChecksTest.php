<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\ChangelogReader;
use App\Fleet\Upgrade\Check\InstallerPreflightCheck;
use App\Fleet\Upgrade\Check\RolloutActionCheck;
use App\Fleet\Upgrade\Check\Severity;
use App\Fleet\Upgrade\Check\TargetInstallerSource;
use App\Fleet\Upgrade\Direction;
use App\Fleet\Upgrade\RepoUrl;
use App\Tests\Support\FakeInstallerSource;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PreflightAndRolloutChecksTest extends CheckTestCase
{
    private const string CHANGELOG = <<<'MD'
        # Changelog

        ## [1.3.0] - 2026-03-01

        ### Added
        - three

        #### Rollout
        - ACTION: set FLEET_FOO in fleet.env
        - plain note

        ## [1.2.0] - 2026-02-01

        ### Fixed
        - two

        #### Rollout
        - ACTION: rotate the key

        ## [1.1.0] - 2026-01-01

        ### Added
        - one
        MD;

    private function rollout(string $body = self::CHANGELOG, int $status = 200): RolloutActionCheck
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => $status]));

        return new RolloutActionCheck(new ChangelogReader($http, new RecordingCommandRunner(), new RepoUrl()));
    }

    public function testActionLinesWarnOnePerLineAndNeverBlock(): void
    {
        $results = $this->rollout()->check(self::plan(from: 'v1.1.0', to: 'v1.3.0'));

        self::assertSame([RolloutActionCheck::ACTION, RolloutActionCheck::ACTION], self::ids($results));
        self::assertSame(Severity::Warn, $results[0]->severity);
        self::assertStringContainsString('v1.3.0 needs an operator action: set FLEET_FOO', $results[0]->message);
        self::assertStringContainsString('v1.2.0', $results[1]->message);
        self::assertFalse($results[0]->overridable);
    }

    public function testOnlyTheReleasesBeingInstalledCount(): void
    {
        $results = $this->rollout()->check(self::plan(from: 'v1.2.0', to: 'v1.3.0'));

        self::assertCount(1, $results);
        self::assertStringContainsString('v1.3.0', $results[0]->message);
    }

    public function testADowngradeOrNoTargetHasNoRolloutToAct(): void
    {
        self::assertSame([], $this->rollout()->check(self::plan(from: 'v1.3.0', to: 'v1.1.0', direction: Direction::Down)));
        self::assertSame([], $this->rollout()->check(self::plan(to: null, direction: Direction::Unknown)));
    }

    public function testAnUnreadableChangelogOnlyInforms(): void
    {
        $results = $this->rollout('', 404)->check(self::plan(from: 'v1.1.0', to: 'v1.3.0'));

        self::assertSame([RolloutActionCheck::UNREADABLE], self::ids($results));
        self::assertSame(Severity::Info, $results[0]->severity);
    }

    private string $dir = '';

    protected function tearDown(): void
    {
        if ('' !== $this->dir) {
            new Filesystem()->remove($this->dir);
        }
    }

    private function source(string $runSh): FakeInstallerSource
    {
        $this->dir = sys_get_temp_dir().'/fleet-target-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/install', 0o700, true);
        file_put_contents($this->dir.'/install/run.sh', $runSh);

        return new FakeInstallerSource($this->dir);
    }

    private function preflight(TargetInstallerSource $source, RecordingCommandRunner $runner): InstallerPreflightCheck
    {
        return new InstallerPreflightCheck($source, $runner, 'https://fleet.example.test');
    }

    public function testATargetWithoutThePreflightFlagIsSkippedWithAnInfo(): void
    {
        $source = $this->source("#!/usr/bin/env bash\necho hi\n");
        $runner = new RecordingCommandRunner();

        $results = $this->preflight($source, $runner)->check(self::plan());

        self::assertSame([InstallerPreflightCheck::UNSUPPORTED], self::ids($results));
        self::assertSame(Severity::Info, $results[0]->severity);
        self::assertSame([], $runner->calls());
        self::assertSame(1, $source->discarded);
    }

    public function testTheTargetsLinesBecomeFindings(): void
    {
        $source = $this->source("# supports --preflight\n");
        $runner = new RecordingCommandRunner()->expect(['bash'], new CommandResult(3, "noise\nBLOCK instance-id-length: 60-char id\nWARN swap: low swap\nINFO php: 8.4\n"));

        $results = $this->preflight($source, $runner)->check(self::plan());

        self::assertSame(['installer.instance-id-length', 'installer.swap', 'installer.php'], self::ids($results));
        self::assertSame([Severity::Block, Severity::Warn, Severity::Info], array_map(static fn ($r) => $r->severity, $results));
        self::assertFalse($results[0]->overridable);
        $call = $runner->calls()[0];
        self::assertContains('--preflight', $call->argv);
        self::assertContains('--ref=v1.3.0', $call->argv);
        self::assertContains('--domain=fleet.example.test', $call->argv);
        self::assertSame(1, $source->discarded);
    }

    public function testAFailureWithNoBlockLineStillBlocks(): void
    {
        $source = $this->source("# --preflight\n");
        $runner = new RecordingCommandRunner()->expect(['bash'], new CommandResult(1, 'segfault'));

        self::assertSame([InstallerPreflightCheck::FAILED], self::ids($this->preflight($source, $runner)->check(self::plan())));
    }

    public function testAnUnfetchableTargetOnlyInforms(): void
    {
        $source = new FakeInstallerSource(null);

        $results = $this->preflight($source, new RecordingCommandRunner())->check(self::plan());

        self::assertSame([InstallerPreflightCheck::UNAVAILABLE], self::ids($results));
    }
}
