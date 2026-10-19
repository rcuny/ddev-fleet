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
        yield 'anonymous components' => ['none', '/_components/anything', 401];
        yield 'member components' => ['member', '/_components/anything', 403];
        // Authorised, but there is no such component.
        yield 'admin components' => ['admin', '/_components/anything', 404];
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

    /**
     * The live component endpoint serves any registered component by name, so it is admin-only as a whole
     * (member-facing components arrive with their own checks in M0.04). Every spelling the router resolves to
     * the endpoint must be refused to members and anonymous users; a spelling it does not resolve is a 404 for
     * everybody, which exposes nothing.
     *
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function endpointSpellings(): iterable
    {
        foreach (['GET', 'POST'] as $method) {
            // Routed to the endpoint: decided by the matched route, not by the raw path.
            foreach ([
                'plain' => '/_components/anything',
                'component action' => '/_components/anything/save',
                'encoded underscore' => '/%5Fcomponents/anything',
                'encoded lowercase hex' => '/%5fcomponents/anything',
                'encoded letter' => '/_%63omponents/anything',
                'encoded slash after prefix' => '/_components%2Fanything',
            ] as $label => $path) {
                yield "$method $label member" => ['member', $method, $path, 403];
                yield "$method $label anonymous" => ['none', $method, $path, 401];
            }

            // Not routed at all: the same answer for everybody, nothing is served.
            foreach ([
                'upper case' => '/_Components/anything',
                'all upper case' => '/_COMPONENTS/anything',
                'double slash' => '//_components/anything',
                'dot segment' => '/./_components/anything',
                'no component name' => '/_components/',
                'bare prefix' => '/_components',
            ] as $label => $path) {
                foreach (['member', 'none', 'admin'] as $role) {
                    yield "$method $label $role" => [$role, $method, $path, 404];
                }
            }
        }
    }

    #[DataProvider('endpointSpellings')]
    public function testComponentEndpointSpellings(string $role, string $method, string $path, int $status): void
    {
        $client = self::createClient();
        $client->request($method, $path, server: ['HTTP_X_FLEET_STAND_IN_ROLE' => $role, 'HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame($status, $method.' '.$path);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function adminSpellings(): iterable
    {
        foreach ([
            'plain' => '/admin/projects',
            'encoded letter' => '/admin/%70rojects',
            'encoded prefix letter' => '/%61dmin/projects',
            'encoded slash' => '/admin%2Fprojects',
            'unknown page' => '/admin/projects/nope',
            'trailing slash' => '/admin/projects/',
            'bare' => '/admin',
        ] as $label => $path) {
            yield "$label member" => ['member', $path, 403];
            yield "$label anonymous" => ['none', $path, 401];
        }
        // The router is case-sensitive: nothing is served, nothing is protected.
        foreach (['member', 'none', 'admin'] as $role) {
            yield "upper case $role" => [$role, '/Admin/projects', 404];
            yield "prefix-sharing sibling $role" => [$role, '/administrator', 404];
        }
    }

    #[DataProvider('adminSpellings')]
    public function testAdminSpellings(string $role, string $path, int $status): void
    {
        $client = self::createClient();
        $client->request('GET', $path, server: ['HTTP_X_FLEET_STAND_IN_ROLE' => $role]);

        self::assertResponseStatusCodeSame($status, $path);
    }
}
