<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrontendToolchainTest extends WebTestCase
{
    public function testHomeLinksBuiltTailwindCssThatContainsKitClasses(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'member']);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<script type="importmap"', $html);
        if (1 !== preg_match('#<link rel="stylesheet" href="(/assets/styles/app-[^"]+\.css)"#', $html, $m)) {
            self::fail('No built Tailwind stylesheet is linked; run "composer run assets" (tailwind:build) first.');
        }
        $href = $m[1];

        // The stylesheet is fetched without any identity: /assets/ is outside the firewall.
        $client->request('GET', $href);
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('text/css', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString(
            'bg-primary',
            $client->getInternalResponse()->getContent(),
            'The built CSS lacks kit classes; run "composer run assets" (tailwind:build) first.',
        );
    }
}
