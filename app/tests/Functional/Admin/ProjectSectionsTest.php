<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The ten project tabs (FLE-226): the Overview landing page, the thin pages, and the old addresses.
 */
final class ProjectSectionsTest extends AdminTestCase
{
    private function makeProject(): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', defaultBranch: 'develop', cpuThreads: 2));
    }

    public function testTheOverviewShowsTheProjectReadOnly(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog');

        self::assertResponseIsSuccessful();
        self::assertSame('blog', trim($crawler->filter('main h1')->text()));
        self::assertSame('https://git.example.com/acme/blog.git', trim($crawler->filter('[data-testid=overview-git]')->text()));
        self::assertSame('develop', trim($crawler->filter('[data-testid=overview-branch]')->text()));
        self::assertSame('None', trim($crawler->filter('[data-testid=overview-template]')->text()));
        foreach (['instances', 'templates', 'ports', 'secrets'] as $count) {
            self::assertSame('0', trim($crawler->filter('[data-testid=count-'.$count.']')->text()), $count);
        }
        self::assertStringContainsString('No webhook secret yet', $crawler->filter('[data-testid=overview-webhooks]')->text());
        self::assertStringContainsString('allocatable threads', $crawler->filter('[data-testid=overview-capacity]')->text());
        self::assertCount(9, $crawler->filter('[data-testid=overview-links] a'));
        self::assertCount(0, $crawler->filter('main form'), 'the overview has no form');
    }

    #[DataProvider('thinPages')]
    public function testThePlaceholderPagesRender(string $path, string $testId): void
    {
        $this->makeProject();

        $crawler = $this->open($path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid='.$testId.']'));
        self::assertCount(1, $crawler->filter('h1'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function thinPages(): iterable
    {
        yield 'access' => ['/admin/projects/blog/access', 'project-section-access'];
        yield 'capacity' => ['/admin/projects/blog/capacity', 'project-section-capacity'];
        yield 'transfer' => ['/admin/projects/blog/transfer', 'project-section-transfer'];
    }

    #[DataProvider('oldAddresses')]
    public function testTheOldAddressesRedirectToTheirTab(string $old, string $new): void
    {
        $this->makeProject();

        $this->open($old);

        self::assertResponseRedirects($new);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oldAddresses(): iterable
    {
        yield 'ports' => ['/admin/projects/blog/ports', '/admin/projects/blog/network'];
        yield 'secrets' => ['/admin/projects/blog/secrets', '/admin/projects/blog/keys'];
        yield 'secret replace link' => ['/admin/projects/blog/secrets?name=API_KEY', '/admin/projects/blog/keys?name=API_KEY'];
        yield 'webhooks' => ['/admin/projects/blog/webhooks', '/admin/projects/blog/integrations'];
    }

    #[DataProvider('newPages')]
    public function testEveryTabResolvesAndMarksItselfCurrent(string $path): void
    {
        $this->makeProject();

        $crawler = $this->open($path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav[aria-label="Project sections"] [data-testid=project-sections-strip] a[aria-current=page]'), $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function newPages(): iterable
    {
        foreach (['', '/source', '/templates', '/assets', '/network', '/access', '/capacity', '/keys', '/integrations', '/transfer'] as $suffix) {
            yield 'tab '.($suffix ?: 'overview') => ['/admin/projects/blog'.$suffix];
        }
    }

    public function testAnUnknownProjectIs404OnTheNewPages(): void
    {
        foreach (['', '/source', '/network', '/access', '/capacity', '/transfer', '/keys', '/integrations'] as $suffix) {
            $this->open('/admin/projects/nope'.$suffix);
            self::assertResponseStatusCodeSame(404, $suffix);
        }
    }

    public function testTheRestrictedTabHasALockWithAnAccessibleName(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog');

        self::assertStringContainsString('(restricted)', $crawler->filter('[data-testid=project-sections-strip] a[href$="/keys"]')->text());
    }
}
