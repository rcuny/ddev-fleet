<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RemoteHeaderAuthTest extends WebTestCase
{
    public function testTrustedClientWithAdminGroupIsAdmin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/x', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_REMOTE_USER' => 'alice',
            'HTTP_REMOTE_GROUPS' => 'fleet-admins',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTrustedClientWithOtherGroupIsMember(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/x', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_REMOTE_USER' => 'alice',
            'HTTP_REMOTE_GROUPS' => 'demo',
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_REMOTE_USER' => 'alice',
            'HTTP_REMOTE_GROUPS' => 'demo',
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testTheHeadersBeatTheStandIn(): void
    {
        $client = self::createClient();
        // The stand-in asks for admin, the proxy says member: the proxy wins.
        $client->request('GET', '/admin/x', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_REMOTE_USER' => 'alice',
            'HTTP_REMOTE_GROUPS' => 'demo',
            'HTTP_X_FLEET_STAND_IN_ROLE' => 'admin',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUntrustedProxyHeaderNeverFallsThroughToStandIn(): void
    {
        $client = self::createClient();
        // The stand-in default is admin; an untrusted peer must still be refused outright.
        $client->request('GET', '/', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_REMOTE_USER' => 'alice',
            'HTTP_REMOTE_GROUPS' => 'fleet-admins',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidUsernameFromATrustedPeerIs401(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_REMOTE_USER' => 'bad user']);

        self::assertResponseStatusCodeSame(401);
    }
}
