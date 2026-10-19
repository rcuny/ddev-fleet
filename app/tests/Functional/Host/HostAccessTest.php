<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Fleet\Backup\BackupService;
use App\Tests\Support\HostPageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every route of the host settings page: anonymous gets 401 (the app's convention, plain text), a member 403 (inside
 * the shell), only an administrator gets in. A member's valid POST changes nothing.
 */
final class HostAccessTest extends HostPageTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'page' => ['GET', '/admin/host'];
        yield 'git tab' => ['GET', '/admin/host?tab=git'];
        yield 'backups tab' => ['GET', '/admin/host?tab=backups'];
        yield 'download' => ['GET', '/admin/host/backups/fleet-20261019-083015.sqlite'];
        yield 'general' => ['POST', '/admin/host/general'];
        yield 'git' => ['POST', '/admin/host/git'];
        yield 'claude' => ['POST', '/admin/host/claude'];
        yield 'notifications' => ['POST', '/admin/host/notifications'];
        yield 'uploads tab' => ['GET', '/admin/host?tab=uploads'];
        yield 'upload limit' => ['POST', '/admin/host/uploads'];
        yield 'backup now' => ['POST', '/admin/host/backups'];
        yield 'retention' => ['POST', '/admin/host/backups/retention'];
    }

    #[DataProvider('routes')]
    public function testAnonymousIsUnauthorised(string $method, string $path): void
    {
        $this->as('none');
        $this->client->request($method, $path);

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    #[DataProvider('routes')]
    public function testMemberIsForbidden(string $method, string $path): void
    {
        $this->as('member');
        $this->client->request($method, $path);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminReachesThePageAndItsNavEntry(): void
    {
        $crawler = $this->open('/admin/host');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Host settings', trim($crawler->filter('h1')->text()));
        self::assertCount(1, $crawler->filter('h1'));
        self::assertGreaterThan(0, $crawler->filter('[data-shell=sidebar] a[href="/admin/host"], nav a[href="/admin/host"]')->count());
    }

    public function testAMembersValidPostChangesNothing(): void
    {
        $this->as('member');
        $this->client->request('POST', '/admin/host/general', ['host_general' => ['acmeEmail' => 'ops@example.com', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/host/git', ['host_git_identity' => ['name' => 'Bot', 'email' => 'bot@example.com', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/host/claude', ['host_claude_token' => ['token' => 'sk-member-token-0123456789', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/host/backups', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->host()->acmeEmail());
        self::assertNull($this->host()->gitUserName());
        self::assertFalse($this->secretStore()->hasFleetWide('claude_oauth_token'));
        $backups = self::getContainer()->get(BackupService::class);
        self::assertInstanceOf(BackupService::class, $backups);
        self::assertSame([], $backups->list());
    }

    public function testWrongMethodsAreNeverServed(): void
    {
        // A route that exists for another method falls through to the /admin catch-all: 404, not the page.
        $this->client->request('GET', '/admin/host/general');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/admin/host');
        self::assertResponseStatusCodeSame(404);
    }
}
