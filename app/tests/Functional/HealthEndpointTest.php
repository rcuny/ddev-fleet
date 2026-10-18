<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Health\HealthChecker;
use App\Health\HealthReport;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthEndpointTest extends WebTestCase
{
    public function testAnonymousHealthIsOkAndUncacheable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            '{"status":"ok","checks":{"fleet_db":"ok","queue_db":"ok","home_writable":"ok"}}',
            $client->getResponse()->getContent(),
        );
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testFailingCheckGives503NoStoreAndNamesOnlyTheCheck(): void
    {
        $client = self::createClient();
        $checker = $this->createStub(HealthChecker::class);
        $checker->method('check')->willReturn(new HealthReport(['fleet_db' => true, 'queue_db' => false, 'home_writable' => true]));
        self::getContainer()->set(HealthChecker::class, $checker);

        $client->request('GET', '/health', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(
            '{"status":"fail","checks":{"fleet_db":"ok","queue_db":"fail","home_writable":"ok"}}',
            $client->getResponse()->getContent(),
        );
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }
}
