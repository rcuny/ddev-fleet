<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Ui\NavigationCatalog;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlaceholderPagesTest extends WebTestCase
{
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
