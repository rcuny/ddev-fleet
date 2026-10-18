<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AppShellTest extends WebTestCase
{
    private KernelBrowser $client;

    private const array ADMIN_LABELS = ['Home', 'Instances', 'Projects', 'Jobs', 'Webhooks', 'Schedules', 'Secrets', 'Users', 'Settings', 'Design system'];

    public function testAdminShellLandmarks(): void
    {
        $crawler = $this->page('admin', '/');
        self::assertResponseIsSuccessful();

        self::assertSame(self::ADMIN_LABELS, $this->texts($crawler, 'nav[aria-label="Main"] a'));

        $firstLink = $crawler->filter('body a')->first();
        self::assertSame('#main', $firstLink->attr('href'));
        self::assertSame('Skip to content', trim($firstLink->text()));
        self::assertCount(1, $crawler->filter('main#main'));
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertStringContainsString('Dev Admin', $crawler->filter('[data-testid=identity]')->text());
        self::assertGreaterThan(0, $crawler->filter('[data-testid=stand-in-switch]')->count());
        self::assertCount(1, $crawler->filter('[data-shell=topbar] button[aria-label="Toggle dark mode"]'));
    }

    public function testIdentityMenuShowsNameAndGroups(): void
    {
        $crawler = $this->page('admin', '/');
        $identity = $crawler->filter('[data-testid=identity]')->text();

        self::assertStringContainsString('Dev Admin', $identity);
        self::assertStringContainsString('fleet-admins', $identity);
    }

    public function testThemeBootScriptComesBeforeTheStylesheet(): void
    {
        $this->page('admin', '/');
        $html = (string) $this->client->getResponse()->getContent();
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        $boot = strpos($head, "localStorage.getItem('fleet-theme')");
        $stylesheet = strpos($head, 'rel="stylesheet"');

        self::assertIsInt($boot);
        self::assertIsInt($stylesheet);
        self::assertLessThan($stylesheet, $boot);
        self::assertStringContainsString('viewport-fit=cover', $head);
    }

    public function testPageTitles(): void
    {
        self::assertSame('Home · Fleet', $this->page('admin', '/')->filter('title')->text());
        self::assertSame('Projects · Fleet', $this->page('admin', '/admin/projects')->filter('title')->text());
        self::assertSame('Design system · Fleet', $this->page('admin', '/admin/design-system')->filter('title')->text());
    }

    public function testCurrentPageIsMarked(): void
    {
        $crawler = $this->page('admin', '/admin/projects');

        $current = $crawler->filter('nav[aria-label="Main"] a[aria-current="page"]');
        self::assertCount(1, $current);
        self::assertSame('Projects', trim($current->text()));
    }

    public function testDesignSystemRendersInsideTheShell(): void
    {
        $crawler = $this->page('admin', '/admin/design-system');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('nav[aria-label="Main"]'));
        self::assertCount(1, $crawler->filter('main#main'));
        // The reference page previews headings, so it has more h1 elements than a normal page; its own title comes first.
        self::assertSame('Design system', trim($crawler->filter('main h1')->first()->text()));
        // The shell owns the header and its ThemeToggle; the page has none of its own (its samples live in <main>).
        self::assertCount(1, $crawler->filter('[data-shell=topbar] button[aria-label="Toggle dark mode"]'));
        self::assertCount(0, $crawler->filter('main [data-shell=topbar]'));
    }

    public function testMemberMenuIsHomeAndInstances(): void
    {
        $crawler = $this->page('member', '/');
        self::assertResponseIsSuccessful();

        self::assertSame(['Home', 'Instances'], $this->texts($crawler, 'nav[aria-label="Main"] a'));
        self::assertStringContainsString('Dev Member', $crawler->filter('[data-testid=identity]')->text());
    }

    public function testMemberGets403WithTheShell(): void
    {
        $crawler = $this->page('member', '/admin/projects');
        self::assertResponseStatusCodeSame(403);

        self::assertStringContainsString('You do not have access to this page', $crawler->filter('main#main')->text());
        self::assertSame(['Home', 'Instances'], $this->texts($crawler, 'nav[aria-label="Main"] a'));
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('Forbidden · Fleet', $crawler->filter('title')->text());
    }

    public function testAnonymousStillGetsPlainText401(): void
    {
        $this->page('none', '/admin/projects');

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testBottomNavForAdmin(): void
    {
        $crawler = $this->page('admin', '/');

        $nav = $crawler->filter('nav[aria-label="Primary"][data-bottom-nav]');
        self::assertCount(1, $nav);
        self::assertSame(['Home', 'Instances', 'Jobs', 'More'], $this->texts($crawler, 'nav[aria-label="Primary"] a, nav[aria-label="Primary"] button'));

        $more = $crawler->filter('#more-sheet');
        self::assertCount(1, $more);
        self::assertSame(
            ['Projects', 'Webhooks', 'Schedules', 'Secrets', 'Users', 'Settings', 'Design system'],
            $this->texts($crawler, '#more-sheet [data-more-item]'),
        );
        self::assertStringContainsString('Dev Admin', $more->filter('[data-testid=more-identity]')->text());
        self::assertCount(1, $more->filter('[data-testid=stand-in-switch]'));
    }

    public function testBottomNavForMemberHasAnIdentityBlockInTheSheet(): void
    {
        $crawler = $this->page('member', '/');

        self::assertSame(['Home', 'Instances', 'More'], $this->texts($crawler, 'nav[aria-label="Primary"] a, nav[aria-label="Primary"] button'));
        $more = $crawler->filter('#more-sheet');
        self::assertCount(0, $more->filter('[data-more-item]'));
        self::assertStringContainsString('Dev Member', $more->filter('[data-testid=more-identity]')->text());
    }

    public function testStandInSwitchLinksKeepTheCurrentPage(): void
    {
        $crawler = $this->page('admin', '/admin/projects?x=1');

        $hrefs = $crawler->filter('[data-testid=stand-in-switch] a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        // Rendered twice: the desktop sidebar and the phone More sheet.
        self::assertCount(4, $hrefs);

        $targets = [];
        foreach ($hrefs as $href) {
            parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
            $targets[(string) parse_url($href, \PHP_URL_PATH)] = $query['next'] ?? null;
        }
        self::assertSame([
            '/_dev/stand-in/admin' => '/admin/projects?x=1',
            '/_dev/stand-in/member' => '/admin/projects?x=1',
        ], $targets);
    }

    public function testHomeShowsTheRoleSummary(): void
    {
        $summary = $this->page('admin', '/')->filter('[data-testid=role-summary]');
        self::assertCount(1, $summary);
        self::assertStringContainsString('Dev Admin', $summary->text());
        self::assertStringContainsString('Administrator', $summary->text());

        $summary = $this->page('member', '/')->filter('[data-testid=role-summary]');
        self::assertStringContainsString('Member', $summary->text());
    }

    private function page(string $role, string $path): Crawler
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();

        return $this->client->request('GET', $path, server: ['HTTP_X_FLEET_STAND_IN_ROLE' => $role]);
    }

    /**
     * @return list<string>
     */
    private function texts(Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector)->each(static fn (Crawler $n): string => trim($n->text()));
    }
}
