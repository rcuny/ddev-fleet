<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Domain\Project\NoProjectMembership;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectMembership;
use App\Security\FleetUser;
use App\Security\ProjectVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ProjectVoterTest extends TestCase
{
    private function voter(?Project $memberOf = null): ProjectVoter
    {
        return new ProjectVoter(new class($memberOf) implements ProjectMembership {
            public function __construct(private readonly ?Project $project)
            {
            }

            public function isMember(FleetUser $user, Project $project): bool
            {
                return $project === $this->project;
            }
        });
    }

    private function token(string $group): TokenInterface
    {
        $user = new FleetUser('alice', 'Alice', null, [$group], 'fleet-admins');

        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function admin(): TokenInterface
    {
        return $this->token('fleet-admins');
    }

    private function member(): TokenInterface
    {
        return $this->token('demo');
    }

    public function testAdminMayDoEverything(): void
    {
        $voter = $this->voter();
        $project = new Project('acme', 'git@git.example.com:acme/blog.git');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->admin(), $project, [ProjectVoter::SETTINGS]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->admin(), $project, [ProjectVoter::OPERATE]));
    }

    public function testMemberNeverGetsSettings(): void
    {
        $project = new Project('acme', 'git@git.example.com:acme/blog.git');
        // Even a member of the project does not see its settings.
        $voter = $this->voter($project);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->member(), $project, [ProjectVoter::SETTINGS]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->member(), null, [ProjectVoter::SETTINGS]));
    }

    public function testMemberOperatesOnlyProjectsTheyBelongTo(): void
    {
        $own = new Project('acme', 'git@git.example.com:acme/blog.git');
        $other = new Project('blog', 'git@git.example.com:acme/other.git');
        $voter = $this->voter($own);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->member(), $own, [ProjectVoter::OPERATE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->member(), $other, [ProjectVoter::OPERATE]));
    }

    public function testDeployAndDestroyAreAdminOnly(): void
    {
        $own = new Project('acme', 'git@git.example.com:acme/blog.git');
        // Even a member of the project may neither deploy nor destroy.
        $voter = $this->voter($own);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->admin(), $own, [ProjectVoter::DEPLOY]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->admin(), null, [ProjectVoter::DEPLOY]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->admin(), $own, [ProjectVoter::DESTROY]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->member(), $own, [ProjectVoter::DEPLOY]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->member(), null, [ProjectVoter::DEPLOY]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->member(), $own, [ProjectVoter::DESTROY]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->admin(), null, [ProjectVoter::DESTROY]), 'destroy needs a project');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), $own, [ProjectVoter::DEPLOY]));
    }

    public function testDefaultMembershipMakesNobodyAMember(): void
    {
        $voter = new ProjectVoter(new NoProjectMembership());
        $project = new Project('acme', 'git@git.example.com:acme/blog.git');

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->member(), $project, [ProjectVoter::OPERATE]));
    }

    public function testSettingsWithoutSubjectIsAdminOnly(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->admin(), null, [ProjectVoter::SETTINGS]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->member(), null, [ProjectVoter::SETTINGS]));
    }

    public function testOperateWithoutSubjectAbstains(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->admin(), null, [ProjectVoter::OPERATE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->member(), null, [ProjectVoter::OPERATE]));
    }

    public function testAnonymousIsDenied(): void
    {
        $voter = $this->voter();
        $project = new Project('acme', 'git@git.example.com:acme/blog.git');

        foreach ([ProjectVoter::SETTINGS, ProjectVoter::OPERATE, ProjectVoter::DEPLOY, ProjectVoter::DESTROY] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), $project, [$attribute]));
        }
        foreach ([ProjectVoter::SETTINGS, ProjectVoter::DEPLOY] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, [$attribute]));
        }
    }

    public function testOtherAttributesAreAbstained(): void
    {
        $voter = $this->voter();
        $project = new Project('acme', 'git@git.example.com:acme/blog.git');

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->admin(), $project, ['ROLE_ADMIN']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->admin(), $project, ['VIEW']));
        // Settings of something that is not a project are not this voter's business.
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->admin(), new \stdClass(), [ProjectVoter::SETTINGS]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->admin(), 'acme', [ProjectVoter::OPERATE]));
    }

    public function testAnotherUserTypeIsDenied(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('bob', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, null, [ProjectVoter::SETTINGS]));
    }
}
