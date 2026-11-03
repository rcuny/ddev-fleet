<?php

declare(strict_types=1);

namespace App\Tests\Integration\Upgrade;

use App\Fleet\Shell\ProcessCommandRunner;
use App\Fleet\Upgrade\ChangelogReader;
use App\Fleet\Upgrade\ChangelogSection;
use App\Fleet\Upgrade\GitRemoteTags;
use App\Fleet\Upgrade\ReleaseCatalog;
use App\Fleet\Upgrade\RepoUrl;
use App\Fleet\Upgrade\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Process\Process;

/**
 * The catalog against a real (temporary) bare repository with tags, run through the real command runner.
 */
final class ReleaseCatalogTest extends TestCase
{
    private string $dir;
    private string $remote;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/release-catalog-'.bin2hex(random_bytes(4));
        $work = $this->dir.'/work';
        $this->remote = $this->dir.'/remote.git';
        mkdir($work, 0o777, true);

        $this->git($work, 'init', '-q', '-b', 'main');
        $this->git($work, 'config', 'user.name', 'T');
        $this->git($work, 'config', 'user.email', 't@example.test');
        $this->git($work, 'config', 'commit.gpgsign', 'false');
        $this->git($work, 'config', 'tag.gpgsign', 'false');
        $this->release($work, '1.1.0', "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-01\n\n### Added\n- one\n");
        $this->release($work, '1.2.0', "# Changelog\n\n## [Unreleased]\n\n## [1.2.0] - 2026-02-01\n\n### Added\n- two\n\n#### Rollout\n- ACTION: do it\n\n## [1.1.0] - 2026-01-01\n\n### Added\n- one\n");
        $this->release($work, '1.3.0-rc.1', "# Changelog\n");
        $this->git($work, 'tag', 'v1.4.0'); // lightweight: not a release
        $this->release($work, '0.20.1', "# old\n", 'v0.20.1');
        $this->git($this->dir, 'clone', '-q', '--bare', $work, $this->remote);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    private function git(string $cwd, string ...$args): void
    {
        $process = new Process(['git', ...$args], $cwd);
        $process->mustRun();
    }

    private function release(string $work, string $version, string $changelog, ?string $tag = null): void
    {
        file_put_contents($work.'/CHANGELOG.md', $changelog);
        $this->git($work, 'add', '.');
        $this->git($work, 'commit', '-q', '-m', 'release '.$version);
        $this->git($work, 'tag', '-a', $tag ?? 'v'.$version, '-m', 'release '.$version);
    }

    private function catalog(string $url, ArrayAdapter $cache, MockClock $clock): ReleaseCatalog
    {
        return new ReleaseCatalog($cache, new GitRemoteTags(new ProcessCommandRunner(), new RepoUrl($url)), $clock);
    }

    public function testListsAnnotatedV1TagsSemverSortedAndHidesPreReleasesFromTheStableList(): void
    {
        $state = $this->catalog($this->remote, new ArrayAdapter(), new MockClock('2026-11-03 10:00:00 UTC'))->refresh();

        self::assertNull($state->error);
        self::assertSame(['v1.3.0-rc.1', 'v1.2.0', 'v1.1.0'], array_map(static fn ($r): string => $r->tag(), $state->releases));
        self::assertSame(['v1.2.0', 'v1.1.0'], array_map(static fn ($r): string => $r->tag(), $state->stable()));
        self::assertSame('v1.2.0', $state->latest()?->tag());
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $state->stable()[0]->sha);
    }

    public function testRefreshIsIdempotentAndStateReadsTheCacheOnly(): void
    {
        $cache = new ArrayAdapter();
        $clock = new MockClock('2026-11-03 10:00:00 UTC');
        $catalog = $this->catalog($this->remote, $cache, $clock);
        self::assertSame([], $catalog->state()->releases, 'nothing cached yet, and no network');
        self::assertFalse($catalog->isFresh());

        $first = $catalog->refresh();
        $second = $catalog->refresh();

        self::assertEquals($first, $second);
        self::assertEquals($first, $catalog->state());
        self::assertTrue($catalog->isFresh());
        $clock->modify('+7 hours');
        self::assertFalse($catalog->isFresh(), 'older than the 6 h TTL');
    }

    public function testAnUnreachableSourceKeepsTheStaleListWithTheErrorAndNeverThrows(): void
    {
        $cache = new ArrayAdapter();
        $clock = new MockClock('2026-11-03 10:00:00 UTC');
        $this->catalog($this->remote, $cache, $clock)->refresh();

        $clock->modify('+1 day');
        $offline = $this->catalog($this->dir.'/does-not-exist.git', $cache, $clock);
        $state = $offline->refresh();

        self::assertNotNull($state->error);
        self::assertStringContainsString('git ls-remote exited', $state->error);
        self::assertSame(['v1.3.0-rc.1', 'v1.2.0', 'v1.1.0'], array_map(static fn ($r): string => $r->tag(), $state->releases));
        self::assertSame('2026-11-04T10:00:00+00:00', $state->checkedAt?->format(\DATE_ATOM));
        self::assertEquals($state, $offline->state());
        self::assertFalse($offline->isFresh(), 'a failed check is never fresh');
    }

    public function testAnUnreachableSourceWithNothingCachedGivesAnEmptyListAndTheError(): void
    {
        $state = $this->catalog($this->dir.'/nope.git', new ArrayAdapter(), new MockClock('2026-11-03 10:00:00 UTC'))->refresh();

        self::assertSame([], $state->releases);
        self::assertNull($state->latest());
        self::assertNotNull($state->error);
    }

    public function testACorruptCacheItemReadsAsEmpty(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem(ReleaseCatalog::CACHE_KEY);
        $item->set(['releases' => [['tag' => 'v1.2.0', 'sha' => 'short'], 'x'], 'checkedAt' => 'yesterday', 'error' => 5]);
        $cache->save($item);

        $state = $this->catalog($this->remote, $cache, new MockClock())->state();

        self::assertSame([], $state->releases);
        self::assertNull($state->checkedAt);
        self::assertNull($state->error);
    }

    public function testTheChangelogOfTheFakeRemoteIsReadBetweenTwoVersions(): void
    {
        $reader = new ChangelogReader(new MockHttpClient(), new ProcessCommandRunner(), new RepoUrl($this->remote));

        $sections = $reader->between(Version::parse('1.1.0'), Version::parse('1.2.0'));

        self::assertSame(['1.2.0'], array_map(static fn (ChangelogSection $s): string => (string) $s->version, $sections));
        self::assertSame(['do it'], $sections[0]->rollout->actions());
    }
}
