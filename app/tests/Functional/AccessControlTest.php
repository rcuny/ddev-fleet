<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccessControlTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function matrix(): iterable
    {
        yield 'anonymous home' => ['none', '/', 401];
        yield 'anonymous admin' => ['none', '/admin/x', 401];
        yield 'member home' => ['member', '/', 200];
        yield 'member admin' => ['member', '/admin/x', 403];
        yield 'admin home' => ['admin', '/', 200];
        // Authorised, but there is no such route.
        yield 'admin admin' => ['admin', '/admin/x', 404];
        yield 'anonymous health' => ['none', '/health', 200];
    }

    #[DataProvider('matrix')]
    public function testAccess(string $role, string $path, int $status): void
    {
        $client = self::createClient();
        $client->request('GET', $path, server: ['HTTP_X_FLEET_STAND_IN_ROLE' => $role]);

        self::assertResponseStatusCodeSame($status);
    }

    public function testUnauthenticatedAnswerIsPlainText401(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testEnvironmentDefaultIsAdmin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/x');

        self::assertResponseStatusCodeSame(404);
    }
}
