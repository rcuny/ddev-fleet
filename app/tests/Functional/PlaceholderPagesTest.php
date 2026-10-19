<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Ui\NavigationCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlaceholderPagesTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function placeholders(): iterable
    {
        yield 'webhooks' => ['/admin/webhooks', 'Webhooks', 'M0.08', 'webhooks'];
        yield 'schedules' => ['/admin/schedules', 'Schedules', 'M0.09', 'schedules'];
    }

    #[DataProvider('placeholders')]
    public function testAdminSeesThePlaceholder(string $path, string $label, string $milestone, string $routeName): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $path, server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin']);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame($label, trim($crawler->filter('h1')->text()));
        self::assertStringContainsString('Planned for '.$milestone, $crawler->filter('[data-testid=placeholder]')->text());
        self::assertSame($routeName, self::getContainer()->get('router')->match($path)['_route']);
    }

    public function testMemberSeesNoAdminPages(): void
    {
        $client = self::createClient();

        $catalog = self::getContainer()->get(NavigationCatalog::class);
        $adminPaths = [];
        foreach ($catalog->all() as $item) {
            if ('ROLE_ADMIN' === $item->role) {
                $adminPaths[] = $item->path;
            }
        }
        self::assertCount(8, $adminPaths);

        foreach ($adminPaths as $path) {
            $client->request('GET', $path, server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'member']);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    public function testHomeIsNotAPlaceholder(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin']);

        self::assertSame('Home', trim($crawler->filter('h1')->text()));
        self::assertCount(0, $crawler->filter('[data-testid=placeholder]'));
    }

    public function testEveryCatalogRouteResolvesToItsPath(): void
    {
        self::createClient();
        $router = self::getContainer()->get('router');
        $catalog = self::getContainer()->get(NavigationCatalog::class);

        foreach ($catalog->all() as $item) {
            self::assertSame($item->path, $router->generate($item->routeName), $item->key);
            self::assertSame($item->routeName, $router->match($item->path)['_route'], $item->key);
        }
    }

    public function testUnknownAdminPathIsStill404ForAdmin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/nothing-here', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin']);

        self::assertResponseStatusCodeSame(404);
    }
}
