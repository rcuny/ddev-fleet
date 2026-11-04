<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\ChangelogCache;
use App\Fleet\Upgrade\ReleaseCatalog;
use App\Tests\Support\AdminTestCase;
use Psr\Cache\CacheItemPoolInterface;

final class UpgradePageTest extends AdminTestCase
{
    private const string PAGE = '/admin/host/upgrade';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearCaches();
        $this->runner()->reset();
    }

    protected function tearDown(): void
    {
        $this->clearCaches();
        parent::tearDown();
    }

    public function testAdminSeesBadgeAndPageWhenBehind(): void
    {
        $this->install('v1.1.0');
        $this->seedCatalog(['v1.2.0', 'v1.1.0'], '2026-11-03T10:00:00+00:00');
        $this->seedChangelog('v1.2.0', [['version' => '1.2.0', 'date' => '2026-11-03', 'body' => "### Added\n- <b>x</b>", 'notes' => ['ACTION: rotate the key', 'plain note']]]);

        $crawler = $this->open('/');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('v1.1.0', trim($crawler->filter('[data-testid=upgrade-notice-version]')->first()->text()));
        $badge = $crawler->filter('a[data-testid=upgrade-notice-badge]')->first();
        self::assertSame('v1.2.0 available', trim($badge->text()));
        self::assertSame(self::PAGE, $badge->attr('href'));

        $page = $this->open(self::PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('v1.1.0', trim($page->filter('[data-testid=upgrade-installed]')->text()));
        self::assertSame('v1.2.0', trim($page->filter('[data-testid=upgrade-latest]')->text()));
        self::assertSame('sudo fleet upgrade', $page->filter('[data-testid=upgrade-cmd-run]')->attr('value'));
        self::assertSame('sudo fleet upgrade --to v1.2.0', $page->filter('[data-testid=upgrade-cmd-to]')->attr('value'));
        self::assertStringContainsString('rotate the key', $page->filter('[data-testid=upgrade-actions]')->text());
        self::assertCount(1, $page->filter('[data-testid=upgrade-section]'));
        // The changelog is untrusted text: shown escaped, never as markup.
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $this->body());
        self::assertCount(0, $page->filter('[data-testid=upgrade-section] b'));
        self::assertCount(1, $page->filter('[data-testid=upgrade-history-empty]'));
        self::assertCount(1, $page->filter('[data-testid=upgrade-preflight]'));
        self::assertCount(0, $page->filter('[data-testid=upgrade-offline]'));
    }

    public function testNoBadgeWhenCurrent(): void
    {
        $this->install('v1.2.0');
        $this->seedCatalog(['v1.2.0'], '2026-11-03T10:00:00+00:00');

        $crawler = $this->open('/');
        self::assertSame('v1.2.0', trim($crawler->filter('[data-testid=upgrade-notice-version]')->first()->text()));
        self::assertCount(0, $crawler->filter('[data-testid=upgrade-notice-badge]'));

        $page = $this->open(self::PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('[data-testid=upgrade-behind]'));
        self::assertCount(0, $page->filter('[data-testid=upgrade-changes]'));
        self::assertCount(0, $page->filter('[data-testid=upgrade-cmd-to]'));
        self::assertStringContainsString('up to date', $page->filter('[data-testid=upgrade-versions]')->text());
    }

    public function testNoBadgeWhenTheCacheIsEmpty(): void
    {
        $this->install('v1.1.0');

        $crawler = $this->open('/');
        self::assertCount(0, $crawler->filter('[data-testid=upgrade-notice-badge]'));

        $page = $this->open(self::PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Unknown', trim($page->filter('[data-testid=upgrade-latest]')->text()));
        self::assertStringContainsString('No release lookup has run', $page->filter('[data-testid=upgrade-checked]')->text());
    }

    public function testBranchInstallIsNeverBehind(): void
    {
        $this->install('main@abc1234');
        $this->seedCatalog(['v1.2.0'], '2026-11-03T10:00:00+00:00');

        self::assertCount(0, $this->open('/')->filter('[data-testid=upgrade-notice-badge]'));
        self::assertCount(0, $this->open(self::PAGE)->filter('[data-testid=upgrade-changes]'));
    }

    public function testRendersOfflineFromTheStaleCacheWithTheReason(): void
    {
        $this->install('v1.1.0');
        $this->seedCatalog(['v1.2.0'], '2026-11-01T08:00:00+00:00', 'could not resolve host');

        $page = $this->open(self::PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('v1.2.0', trim($page->filter('[data-testid=upgrade-latest]')->text()));
        self::assertStringContainsString('could not resolve host', $page->filter('[data-testid=upgrade-offline]')->text());
        self::assertStringContainsString('2026-11-01 08:00 UTC', $page->filter('[data-testid=upgrade-checked]')->text());
        self::assertCount(1, $page->filter('[data-testid=upgrade-changes-empty]'));
    }

    public function testAnExcerptForAnOlderLatestIsNotShown(): void
    {
        $this->install('v1.1.0');
        $this->seedCatalog(['v1.3.0'], '2026-11-03T10:00:00+00:00');
        $this->seedChangelog('v1.2.0', [['version' => '1.2.0', 'date' => null, 'body' => 'STALE-EXCERPT-MARKER', 'notes' => []]]);

        $page = $this->open(self::PAGE);

        self::assertCount(0, $page->filter('[data-testid=upgrade-section]'));
        self::assertStringNotContainsString('STALE-EXCERPT-MARKER', $this->body());
    }

    public function testRenderingNeverTouchesTheNetwork(): void
    {
        $this->install('v1.1.0');
        $this->seedCatalog(['v1.2.0'], '2026-11-03T10:00:00+00:00');
        $this->runner()->reset();

        $this->open('/');
        $this->open(self::PAGE);
        $this->open('/instances');

        self::assertSame([], $this->runner()->calls(), 'a page render must not run git or any other command');
    }

    public function testMembersAndAnonymousCannotSeeTheBadgeOrThePage(): void
    {
        $this->install('v1.1.0');
        $this->seedCatalog(['v1.2.0'], '2026-11-03T10:00:00+00:00');

        $this->as('member');
        $home = $this->open('/');
        self::assertCount(0, $home->filter('[data-testid=upgrade-notice]'));
        self::assertStringNotContainsString('v1.2.0', $this->body());
        $this->open(self::PAGE);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE.self::PAGE.'/check', ['_token' => 'csrf-token'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);

        $this->as('none');
        $this->open(self::PAGE);
        self::assertResponseStatusCodeSame(401);
    }

    public function testUpgradeIsInTheNavigationAndSettingsIsNotCurrentOnIt(): void
    {
        $page = $this->open(self::PAGE);

        self::assertCount(1, $page->filter('nav a[href="'.self::PAGE.'"][aria-current=page]'));
        self::assertCount(0, $page->filter('nav a[href="/admin/host"][aria-current=page]'));
    }

    public function testCheckNowRefreshesTheCatalogAndIsRateLimited(): void
    {
        $this->install('dev');
        $this->runner()->expect(['git', 'ls-remote'], new CommandResult(0, str_repeat('a', 40)."\trefs/tags/v1.4.0^{}\n"));

        $this->post(self::PAGE.'/check', []);
        self::assertResponseRedirects(self::PAGE);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Looked up', $page->filter('[data-testid=flashes]')->text());
        self::assertSame('v1.4.0', trim($page->filter('[data-testid=upgrade-latest]')->text()));
        self::assertCount(1, $this->runner()->callsMatching(['git', 'ls-remote']));

        $this->post(self::PAGE.'/check', []);
        self::assertResponseRedirects(self::PAGE);
        self::assertStringContainsString('Try again in', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertCount(1, $this->runner()->callsMatching(['git', 'ls-remote']), 'the second click must not ask the source');
    }

    public function testCheckNowNeedsACsrfToken(): void
    {
        $this->client->request('POST', self::BASE.self::PAGE.'/check', [], [], $this->browserServer());

        self::assertResponseRedirects(self::PAGE);
        self::assertStringContainsString('expired', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertSame([], $this->runner()->callsMatching(['git', 'ls-remote']));
    }

    public function testCheckNowIsPostOnly(): void
    {
        $this->client->request('GET', self::BASE.self::PAGE.'/check', [], [], $this->browserServer());

        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405]);
    }

    private function install(string $raw): void
    {
        file_put_contents($this->tempHomeDir().'/test-installed-version', $raw."\n");
    }

    private function tempHomeDir(): string
    {
        $home = (string) getenv('FLEET_HOME');
        self::assertDirectoryExists($home);

        return $home;
    }

    /**
     * @param list<string> $tags newest first
     */
    private function seedCatalog(array $tags, string $checkedAt, ?string $error = null): void
    {
        $item = $this->cache()->getItem(ReleaseCatalog::CACHE_KEY);
        $item->set([
            'releases' => array_map(static fn (string $t): array => ['tag' => $t, 'sha' => str_repeat('a', 40)], $tags),
            'checkedAt' => $checkedAt,
            'error' => $error,
        ]);
        $this->cache()->save($item);
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private function seedChangelog(string $tag, array $sections): void
    {
        $item = $this->cache()->getItem(ChangelogCache::CACHE_KEY);
        $item->set(['tag' => $tag, 'sections' => $sections, 'fetchedAt' => '2026-11-03T10:00:00+00:00']);
        $this->cache()->save($item);
    }

    private function clearCaches(): void
    {
        $this->cache()->deleteItem(ReleaseCatalog::CACHE_KEY);
        $this->cache()->deleteItem(ChangelogCache::CACHE_KEY);
    }

    private function cache(): CacheItemPoolInterface
    {
        $cache = $this->container()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);

        return $cache;
    }

    private function runner(): RecordingCommandRunner
    {
        $runner = $this->container()->get('fleet.command_runner.recording');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return $runner;
    }
}
