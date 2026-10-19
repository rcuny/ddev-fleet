<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectAccessMembership;
use App\Domain\Project\ProjectMembership;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Security\FleetUser;
use App\Security\ProjectVoter;
use App\Tests\Support\FakeInstanceGuard;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The real wiring: project_access rows decide ProjectVoter::OPERATE for a member, through the container's membership
 * service and authorization checker. SETTINGS and a project the user is not in stay refused.
 */
final class ProjectMembershipVoterTest extends KernelTestCase
{
    use FleetDatabase;

    private ProjectAccessService $access;
    private ProjectRepository $projects;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $users = new UserRepository($em);
        $this->projects = new ProjectRepository($em);
        $service = new ProjectService($this->projects, new NamedPortRepository($em), new FakeInstanceGuard());
        $accounts = new UserService($users, new PasswordPolicy(), new AdminGroup('fleet-admins'));
        $accounts->upsertAdmin('alice', 'Alice', 'alice@example.com', 'correct horse battery staple');
        $accounts->upsertAdmin('bob', 'Bob', 'bob@example.com', 'correct horse battery staple');
        foreach (['blog', 'demo'] as $name) {
            $service->create(new ProjectInput(name: $name, git: 'https://git.example.com/acme/'.$name.'.git'));
        }
        $access = self::getContainer()->get(ProjectAccessService::class);
        self::assertInstanceOf(ProjectAccessService::class, $access);
        $this->access = $access;
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param non-empty-string $username
     */
    private function signIn(string $username, string $group): void
    {
        $user = new FleetUser($username, ucfirst($username), null, [$group], 'fleet-admins');
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function checker(): AuthorizationCheckerInterface
    {
        $checker = self::getContainer()->get('security.authorization_checker');
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $checker);

        return $checker;
    }

    private function project(string $name): Project
    {
        $project = $this->projects->findByName($name);
        self::assertNotNull($project);

        return $project;
    }

    public function testTheMembershipServiceIsTheRowBackedOne(): void
    {
        self::assertInstanceOf(ProjectAccessMembership::class, self::getContainer()->get('fleet.test_alias.project_membership'));
        self::assertInstanceOf(ProjectMembership::class, self::getContainer()->get('fleet.test_alias.project_membership'));
    }

    public function testAMemberOperatesTheirProjectsOnly(): void
    {
        $this->access->grant('bob', 'blog');
        $this->signIn('bob', 'demo');

        self::assertTrue($this->checker()->isGranted(ProjectVoter::OPERATE, $this->project('blog')));
        self::assertFalse($this->checker()->isGranted(ProjectVoter::OPERATE, $this->project('demo')));
    }

    public function testAMemberNeverGetsSettingsOrDelete(): void
    {
        $this->access->grant('bob', 'blog');
        $this->signIn('bob', 'demo');

        self::assertFalse($this->checker()->isGranted(ProjectVoter::SETTINGS, $this->project('blog')));
        self::assertFalse($this->checker()->isGranted(ProjectVoter::SETTINGS, null));
    }

    public function testRevokingTakesEffectAtOnce(): void
    {
        $this->access->grant('bob', 'blog');
        $this->signIn('bob', 'demo');
        self::assertTrue($this->checker()->isGranted(ProjectVoter::OPERATE, $this->project('blog')));

        $this->access->revoke('bob', 'blog');

        self::assertFalse($this->checker()->isGranted(ProjectVoter::OPERATE, $this->project('blog')));
    }

    public function testAdminsNeedNoRow(): void
    {
        $this->signIn('alice', 'fleet-admins');

        self::assertTrue($this->checker()->isGranted(ProjectVoter::OPERATE, $this->project('blog')));
        self::assertTrue($this->checker()->isGranted(ProjectVoter::SETTINGS, $this->project('blog')));
    }

    public function testAnIdentityTheDatabaseDoesNotKnowIsNoMember(): void
    {
        $this->access->grant('bob', 'blog');
        $this->signIn('mallory', 'demo');

        self::assertFalse($this->checker()->isGranted(ProjectVoter::OPERATE, $this->project('blog')));
    }

    public function testAProjectWithoutAnIdHasNoMembers(): void
    {
        $membership = self::getContainer()->get('fleet.test_alias.project_membership');
        self::assertInstanceOf(ProjectMembership::class, $membership);

        self::assertFalse($membership->isMember(new FleetUser('bob', 'Bob', null, [], 'fleet-admins'), new Project('new', 'https://git.example.com/x.git')));
    }
}
