<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade;

use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\ChangelogCache;
use App\Fleet\Upgrade\ChangelogReader;
use App\Fleet\Upgrade\RepoUrl;
use App\Fleet\Upgrade\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ChangelogCacheTest extends TestCase
{
    private const string CHANGELOG = <<<'MD'
        # Changelog

        ## [1.2.0] - 2026-11-03

        ### Added
        - the new thing

        #### Rollout
        - ACTION: do this

        ## [1.1.0] - 2026-11-01

        - older
        MD;

    public function testRefreshStoresTheSectionsAfterTheInstalledVersionAndReadReturnsThem(): void
    {
        $cache = $this->cache(new MockResponse(self::CHANGELOG));

        self::assertTrue($cache->refresh(Version::parse('1.1.0'), Version::parse('1.2.0')));

        $excerpt = $cache->read();
        self::assertNotNull($excerpt);
        self::assertSame('1.2.0', (string) $excerpt->upTo);
        self::assertCount(1, $excerpt->sections);
        self::assertSame('1.2.0', (string) $excerpt->sections[0]->version);
        self::assertSame(['do this'], $excerpt->sections[0]->rollout->actions());
        self::assertStringContainsString('the new thing', $excerpt->sections[0]->body);
    }

    public function testALongSectionIsCut(): void
    {
        $long = "## [1.2.0] - 2026-11-03\n\n".str_repeat('x', 3 * ChangelogCache::MAX_BODY_CHARS);
        $cache = $this->cache(new MockResponse($long));

        $cache->refresh(Version::parse('1.1.0'), Version::parse('1.2.0'));

        $excerpt = $cache->read();
        self::assertNotNull($excerpt);
        self::assertSame(ChangelogCache::MAX_BODY_CHARS, mb_strlen($excerpt->sections[0]->body));
    }

    public function testAFailedFetchKeepsThePreviousExcerptAndReportsFalse(): void
    {
        $cache = $this->cache(new MockResponse(self::CHANGELOG), new MockResponse('', ['http_code' => 503]));
        $cache->refresh(Version::parse('1.1.0'), Version::parse('1.2.0'));

        self::assertFalse($cache->refresh(Version::parse('1.1.0'), Version::parse('1.2.0')));
        $excerpt = $cache->read();
        self::assertNotNull($excerpt);
        self::assertCount(1, $excerpt->sections);
    }

    public function testNothingStoredReadsAsNothing(): void
    {
        self::assertNull($this->cache()->read());
    }

    public function testAGarbledCacheEntryReadsAsNothing(): void
    {
        $pool = new ArrayAdapter();
        $item = $pool->getItem(ChangelogCache::CACHE_KEY);
        $item->set('not an array');
        $pool->save($item);

        self::assertNull(new ChangelogCache($pool, $this->reader(), new MockClock())->read());
    }

    private function cache(MockResponse ...$responses): ChangelogCache
    {
        return new ChangelogCache(new ArrayAdapter(), $this->reader(...$responses), new MockClock());
    }

    private function reader(MockResponse ...$responses): ChangelogReader
    {
        return new ChangelogReader(new MockHttpClient($responses), new RecordingCommandRunner(), new RepoUrl());
    }
}
