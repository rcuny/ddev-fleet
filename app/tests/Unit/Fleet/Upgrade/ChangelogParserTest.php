<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\ChangelogParser;
use App\Fleet\Upgrade\ChangelogReader;
use App\Fleet\Upgrade\ChangelogSection;
use App\Fleet\Upgrade\RepoUrl;
use App\Fleet\Upgrade\UpgradeSourceUnavailable;
use App\Fleet\Upgrade\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ChangelogParserTest extends TestCase
{
    private const string CHANGELOG = <<<'MD'
        # Changelog

        ## [Unreleased]

        ### Added
        - not released yet

        ## [1.3.0] - 2026-03-01

        ### Added
        - **FLE-3: three**

        #### Rollout
        - ACTION: set FLEET_FOO in fleet.env
        - plain note that wraps
          onto a second line

        ## [1.2.0] - 2026-02-01

        ### Fixed
        - two

        ## [oops] - broken heading

        - swallowed by nobody

        ## [1.1.0] - 2026-01-01

        ### Added
        - one

        [Unreleased]: https://example.test/compare/v1.3.0...HEAD
        [1.1.0]: https://example.test/releases/tag/v1.1.0
        MD;

    /**
     * @return list<ChangelogSection>
     */
    private function sections(): array
    {
        return new ChangelogParser()->parse(self::CHANGELOG);
    }

    public function testParsesReleasedSectionsInFileOrderAndSkipsUnreleasedAndMalformedHeadings(): void
    {
        $versions = array_map(static fn (ChangelogSection $s): string => (string) $s->version, $this->sections());

        self::assertSame(['1.3.0', '1.2.0', '1.1.0'], $versions);
        self::assertSame('2026-03-01', $this->sections()[0]->date);
    }

    public function testRolloutNotesAndActionLinesAreSeparatedFromTheBody(): void
    {
        $section = $this->sections()[0];

        self::assertSame("### Added\n- **FLE-3: three**", $section->body);
        self::assertSame(['ACTION: set FLEET_FOO in fleet.env', 'plain note that wraps onto a second line'], $section->rollout->notes);
        self::assertSame(['set FLEET_FOO in fleet.env'], $section->rollout->actions());
        self::assertTrue($this->sections()[1]->rollout->isEmpty());
    }

    public function testReferenceLinksAtTheEndAreNotPartOfTheLastSection(): void
    {
        $last = $this->sections()[2];

        self::assertSame("### Added\n- one", $last->body);
    }

    public function testEmptyAndHeadinglessTextGiveNoSections(): void
    {
        self::assertSame([], new ChangelogParser()->parse(''));
        self::assertSame([], new ChangelogParser()->parse("just text\n- bullet\n"));
    }

    public function testBetweenReturnsTheSectionsAfterFromUpToTargetNewestFirst(): void
    {
        $reader = new ChangelogReader(new MockHttpClient(), new RecordingCommandRunner(), new RepoUrl());

        $between = $reader->filter($this->sections(), Version::parse('1.1.0'), Version::parse('1.3.0'));
        self::assertSame(['1.3.0', '1.2.0'], array_map(static fn (ChangelogSection $s): string => (string) $s->version, $between));

        $upToMiddle = $reader->filter($this->sections(), Version::parse('1.1.0'), Version::parse('1.2.0'));
        self::assertSame(['1.2.0'], array_map(static fn (ChangelogSection $s): string => (string) $s->version, $upToMiddle));

        $branchInstall = $reader->filter($this->sections(), null, Version::parse('1.3.0'));
        self::assertSame(['1.3.0'], array_map(static fn (ChangelogSection $s): string => (string) $s->version, $branchInstall));
    }

    public function testReaderFetchesTheChangelogAtTheTagFromRawGithub(): void
    {
        $requested = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse(self::CHANGELOG);
        });
        $reader = new ChangelogReader($http, new RecordingCommandRunner(), new RepoUrl());

        $sections = $reader->between(Version::parse('1.2.0'), Version::parse('1.3.0'));

        self::assertSame(['https://raw.githubusercontent.com/rcuny/ddev-fleet/v1.3.0/CHANGELOG.md'], $requested);
        self::assertSame(['1.3.0'], array_map(static fn (ChangelogSection $s): string => (string) $s->version, $sections));
    }

    public function testReaderRefusesAChangelogOverTheSizeCap(): void
    {
        $http = new MockHttpClient(new MockResponse(str_repeat('x', ChangelogReader::MAX_BYTES + 1)));
        $reader = new ChangelogReader($http, new RecordingCommandRunner(), new RepoUrl());

        $this->expectException(UpgradeSourceUnavailable::class);
        $this->expectExceptionMessage('2 MiB');
        $reader->fetch(Version::parse('1.3.0'));
    }

    public function testReaderReportsHttpErrorsAndTransportFailures(): void
    {
        $notFound = new ChangelogReader(new MockHttpClient(new MockResponse('', ['http_code' => 404])), new RecordingCommandRunner(), new RepoUrl());
        try {
            $notFound->fetch(Version::parse('1.3.0'));
            self::fail('expected an exception');
        } catch (UpgradeSourceUnavailable $e) {
            self::assertStringContainsString('HTTP 404', $e->getMessage());
        }

        $down = new ChangelogReader(new MockHttpClient(new MockResponse('', ['error' => 'could not resolve host'])), new RecordingCommandRunner(), new RepoUrl());
        $this->expectException(UpgradeSourceUnavailable::class);
        $down->fetch(Version::parse('1.3.0'));
    }

    public function testALocalSourceIsReadWithGitShow(): void
    {
        $runner = new RecordingCommandRunner();
        $runner->expect(['git', '--git-dir=/tmp/remote.git', 'show', 'v1.3.0:CHANGELOG.md'], new CommandResult(0, self::CHANGELOG));
        $reader = new ChangelogReader(new MockHttpClient(), $runner, new RepoUrl('/tmp/remote.git'));

        self::assertSame(['1.3.0'], array_map(static fn (ChangelogSection $s): string => (string) $s->version, $reader->between(Version::parse('1.2.0'), Version::parse('1.3.0'))));
    }

    public function testAnOtherRemoteSourceCannotBeRead(): void
    {
        $reader = new ChangelogReader(new MockHttpClient(), new RecordingCommandRunner(), new RepoUrl('https://git.example.test/x.git'));

        $this->expectException(UpgradeSourceUnavailable::class);
        $reader->fetch(Version::parse('1.3.0'));
    }
}
