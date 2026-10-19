<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HostHealthCardTest extends WebTestCase
{
    public function testHomeShowsTheHostCardToAdministratorsOnly(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin']);

        self::assertResponseStatusCodeSame(200);
        $card = $crawler->filter('[data-testid=host-health]');
        self::assertCount(1, $card);
        self::assertCount(1, $card->filter('[data-testid=host-memory]'));
        self::assertCount(1, $card->filter('[data-testid=host-disk]'));
        self::assertCount(1, $card->filter('[data-testid=host-reboot]'));
        self::assertMatchesRegularExpression('/(\d+% used|Not reported)/', $card->filter('[data-testid=host-disk]')->text());
        self::assertCount(1, $crawler->filter('h1'));
        self::ensureKernelShutdown();

        $client = self::createClient();
        $crawler = $client->request('GET', '/', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'member']);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('[data-testid=host-health]'));
        self::assertStringNotContainsString('Reboot required', (string) $client->getResponse()->getContent());
    }
}
