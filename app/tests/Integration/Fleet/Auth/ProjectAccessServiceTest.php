<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Auth;

use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectAccessRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\ProjectAccessRules;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UnknownUser;
use App\Fleet\Auth\UserService;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\FakeInstanceGuard;
use App\Tests\Support\FleetDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectAccessServiceTest extends KernelTestCase
{
    use FleetDatabase;

    private EntityManagerInterface $em;
    private UserRepository $users;
    private ProjectRepository $projects;
    private ProjectAccessService $service;
    private ProjectService $projectService;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->users = new UserRepository($this->em);
        $this->projects = new ProjectRepository($this->em);
        $this->service = new ProjectAccessService($this->users, $this->projects, new ProjectAccessRepository($this->em));
        $this->projectService = new ProjectService($this->projects, new NamedPortRepository($this->em), new FakeInstanceGuard());

        $accounts = new UserService($this->users, new PasswordPolicy(), new AdminGroup('fleet-admins'));
        $accounts->upsertAdmin('alice', 'Alice', 'alice@example.com', 'correct horse battery staple');
        $accounts->upsertAdmin('bob', 'Bob', 'bob@example.com', 'correct horse battery staple');
        foreach (['blog', 'demo', 'shop'] as $name) {
            $this->projectService->create(new ProjectInput(name: $name, git: 'https://git.example.com/acme/'.$name.'.git'));
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function rowCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM project_access');
    }

    public function testGrantCreatesTheMembershipOnceAndRevokeRemovesIt(): void
    {
        self::assertTrue($this->service->grant('alice', 'blog'));
        self::assertFalse($this->service->grant('alice', 'blog'), 'granting again changes nothing');
        self::assertSame(1, $this->rowCount());
        self::assertSame(['blog'], $this->service->projectsOf('alice'));
        self::assertSame(['alice'], $this->service->membersOf('blog'));
        self::assertSame([], $this->service->membersOf('demo'));

        self::assertTrue($this->service->revoke('alice', 'blog'));
        self::assertFalse($this->service->revoke('alice', 'blog'));
        self::assertSame(0, $this->rowCount());
    }

    public function testSetProjectsAddsAndRemovesToMatchTheList(): void
    {
        $this->service->grant('alice', 'blog');
        $this->service->grant('alice', 'demo');

        $result = $this->service->setProjects('alice', ['demo', 'shop', 'shop']);

        self::assertSame(['added' => ['shop'], 'removed' => ['blog']], $result);
        self::assertSame(['demo', 'shop'], $this->service->projectsOf('alice'));
        self::assertSame(['added' => [], 'removed' => []], $this->service->setProjects('alice', ['shop', 'demo']));
        self::assertSame(['added' => [], 'removed' => ['demo', 'shop']], $this->service->setProjects('alice', []));
        self::assertSame(0, $this->rowCount());
    }

    public function testAnUnknownProjectInTheListChangesNothing(): void
    {
        $this->service->grant('alice', 'blog');

        try {
            $this->service->setProjects('alice', ['demo', 'ghost']);
            self::fail('Expected NotFound.');
        } catch (NotFound) {
        }

        self::assertSame(['blog'], $this->service->projectsOf('alice'));
    }

    public function testUnknownUserAndProjectAreRefused(): void
    {
        try {
            $this->service->grant('ghost', 'blog');
            self::fail('Expected UnknownUser.');
        } catch (UnknownUser) {
        }
        try {
            $this->service->grant('alice', 'ghost');
            self::fail('Expected NotFound.');
        } catch (NotFound) {
        }
        $this->expectException(NotFound::class);
        $this->service->membersOf('ghost');
    }

    public function testThePairIsUniqueInTheDatabase(): void
    {
        $this->service->grant('alice', 'blog');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO project_access (user_id, project_id, created_at) SELECT user_id, project_id, created_at FROM project_access',
        );
    }

    public function testDeletingAProjectRemovesItsAccessRows(): void
    {
        $this->service->grant('alice', 'blog');
        $this->service->grant('bob', 'blog');
        $this->service->grant('alice', 'demo');

        $this->projectService->delete('blog');

        self::assertSame(1, $this->rowCount());
        self::assertSame(['demo'], $this->service->projectsOf('alice'));
        self::assertSame([], $this->service->projectsOf('bob'));
    }

    public function testDeletingAUserRemovesTheirAccessRowsOnly(): void
    {
        $this->service->grant('alice', 'blog');
        $this->service->grant('bob', 'blog');
        $this->service->grant('alice', 'demo');
        $alice = $this->users->findByUsername('alice');
        self::assertNotNull($alice);

        $this->users->remove($alice);

        self::assertSame(1, $this->rowCount());
        self::assertSame(['bob'], $this->service->membersOf('blog'));
        self::assertSame([], $this->service->membersOf('demo'));
        self::assertInstanceOf(Project::class, $this->projects->findByName('demo'), 'the project stays');
    }

    public function testRowsDeletedBehindTheEntityManagerDoNotLeaveStaleMembers(): void
    {
        $this->service->grant('alice', 'blog');

        // Another process deletes the project; the rules and member lists read the stored rows.
        $this->em->getConnection()->executeStatement("DELETE FROM project WHERE name = 'blog'");

        self::assertSame(0, $this->rowCount());
        $rules = new ProjectAccessRules(new ProjectAccessRepository($this->em), new AdminGroup('fleet-admins'))->rules();
        self::assertSame(['demo', 'shop'], array_map(static fn ($rule): string => $rule->project, $rules));
    }

    public function testRulesListEveryProjectWithItsMembers(): void
    {
        $this->service->grant('bob', 'demo');
        $this->service->grant('alice', 'demo');

        $rules = new ProjectAccessRules(new ProjectAccessRepository($this->em), new AdminGroup('ops'))->rules();

        self::assertSame(['blog', 'demo', 'shop'], array_map(static fn ($rule): string => $rule->project, $rules));
        self::assertSame([[], ['alice', 'bob'], []], array_map(static fn ($rule): array => $rule->users, $rules));
        self::assertSame('ops', $rules[0]->adminGroup);
    }
}
