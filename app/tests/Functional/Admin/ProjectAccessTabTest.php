<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Fleet\Audit\AuditLog;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;

/**
 * The Access tab (FLE-230): members and site-only users, add and remove from the project, in step with the user page.
 */
final class ProjectAccessTabTest extends AdminTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $users = $this->container()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $users);
        $users->create('alice', 'Alice', 'alice@example.com', self::PASSWORD);
        $users->create('bob', 'Bob', 'bob@example.com', self::PASSWORD, true);
        $users->create('shield', 'Shield', '', self::PASSWORD, false, true);
    }

    /**
     * @return list<string>
     */
    private function projectsOf(string $username): array
    {
        $service = $this->container()->get(ProjectAccessService::class);
        self::assertInstanceOf(ProjectAccessService::class, $service);
        $this->em();

        return $service->projectsOf($username);
    }

    /**
     * @return list<string>
     */
    private function auditActions(): array
    {
        $audit = $this->container()->get(AuditLog::class);
        self::assertInstanceOf(AuditLog::class, $audit);
        $this->em();

        return array_map(static fn ($entry): string => $entry->action().' '.$entry->subject(), $audit->latest());
    }

    public function testThePageListsCandidatesAdminsAndPorts(): void
    {
        $crawler = $this->open('/admin/projects/blog/access');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('[data-testid=members-none]'));
        $options = $crawler->filter('[data-testid=member-select] option')->each(static fn ($o): string => trim($o->text()));
        self::assertSame(['alice', 'shield (site access only)'], $options);
        self::assertStringContainsString('bob', $crawler->filter('[data-testid=admins-note]')->text());
        self::assertCount(1, $crawler->filter('[data-testid=access-ports-none]'));
        self::assertCount(1, $crawler->filter('[data-testid=authelia-note]'));
    }

    public function testAddingAMemberShowsOnTheUserPageAndIsAudited(): void
    {
        $crawler = $this->open('/admin/projects/blog/access');
        $this->submit($crawler, 'Add member', ['username' => 'shield']);

        self::assertResponseRedirects('/admin/projects/blog/access');
        self::assertSame(['blog'], $this->projectsOf('shield'));
        self::assertContains('project.member.add blog/shield', $this->auditActions());

        $crawler = $this->client->followRedirect();
        self::assertSame(1, $crawler->filter('[data-testid=member-row]')->count());
        self::assertCount(1, $crawler->filter('[data-testid=member-site-only]'), 'site-only users are told apart');
        self::assertCount(0, $crawler->filter('[data-testid=member-full]'));

        $user = $this->open('/admin/users/shield/edit');
        self::assertSame('blog', $user->filter('input[type=checkbox][name$="[projects][]"][checked]')->attr('value'));
    }

    public function testAMembershipMadeOnTheUserPageShowsHereAndRemovalWorks(): void
    {
        $access = $this->container()->get(ProjectAccessService::class);
        self::assertInstanceOf(ProjectAccessService::class, $access);
        $access->grant('alice', 'blog');

        $crawler = $this->open('/admin/projects/blog/access');
        self::assertSame(['alice'], $crawler->filter('[data-testid=member-row] td:first-child')->each(static fn ($c): string => trim($c->text())));
        self::assertCount(1, $crawler->filter('[data-testid=member-full]'));

        $this->post('/admin/projects/blog/access/members/alice/remove', []);

        self::assertResponseRedirects('/admin/projects/blog/access');
        self::assertSame([], $this->projectsOf('alice'));
        self::assertContains('project.member.remove blog/alice', $this->auditActions());
    }

    public function testRepeatingAChangeSaysNothingChangedAndWritesNoAudit(): void
    {
        $this->post('/admin/projects/blog/access/members', ['username' => 'alice']);
        $this->post('/admin/projects/blog/access/members', ['username' => 'alice']);
        $this->post('/admin/projects/blog/access/members/shield/remove', []);

        self::assertSame(['project.member.add blog/alice'], $this->auditActions());
    }

    public function testAnUnknownUserChangesNothing(): void
    {
        $this->post('/admin/projects/blog/access/members', ['username' => 'nobody']);

        self::assertResponseRedirects('/admin/projects/blog/access');
        self::assertSame([], $this->auditActions());
    }

    public function testAMemberCannotEditOrSeeThePage(): void
    {
        $this->as('member');

        $this->open('/admin/projects/blog/access');
        self::assertResponseStatusCodeSame(403);
        $this->post('/admin/projects/blog/access/members', ['username' => 'alice']);
        self::assertResponseStatusCodeSame(403);
        $this->post('/admin/projects/blog/access/members/alice/remove', []);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->projectsOf('alice'));
    }
}
