<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Domain\ApiToken\ApiScope;
use App\Domain\ApiToken\ApiToken;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectMembership;
use App\Security\Api\ApiScopeRequest;
use App\Security\Api\ApiTokenUser;
use App\Security\Api\ScopeVoter;
use App\Security\FleetUser;
use App\Security\ProjectVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Scope escalation (FLE-273, SR1): what a token can and cannot reach through the voters.
 */
final class ApiScopeVotersTest extends TestCase
{
    /**
     * @param list<ApiScope> $scopes
     * @param list<string>   $projects
     */
    private function token(array $scopes, array $projects = ['blog']): UsernamePasswordToken
    {
        $entity = new ApiToken(
            't',
            '',
            str_repeat('a', 64),
            'abcdefgh',
            array_map(static fn (ApiScope $s): string => $s->value, $scopes),
            $projects,
            [],
            [],
            'cli',
            new \DateTimeImmutable(),
            new \DateTimeImmutable('+1 day'),
        );
        new \ReflectionProperty($entity, 'id')->setValue($entity, 7);
        $user = ApiTokenUser::fromToken($entity);

        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }

    private function scopeVote(UsernamePasswordToken $token, ApiScope $scope, ?string $project = null): int
    {
        return new ScopeVoter()->vote($token, new ApiScopeRequest($scope, $project), [ScopeVoter::ATTRIBUTE]);
    }

    private function projectVote(UsernamePasswordToken $token, string $attribute, ?Project $project): int
    {
        return new ProjectVoter(new class implements ProjectMembership {
            public function isMember(\Symfony\Component\Security\Core\User\UserInterface $user, Project $project): bool
            {
                return true;
            }
        })->vote($token, $project, [$attribute]);
    }

    public function testAScopeIsHeldOrNot(): void
    {
        $token = $this->token([ApiScope::Read]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->scopeVote($token, ApiScope::Read, 'blog'));
        foreach ([ApiScope::Deploy, ApiScope::Lifecycle, ApiScope::LifecycleDestroy, ApiScope::TerminalRead, ApiScope::TerminalWrite] as $scope) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($token, $scope, 'blog'), $scope->value);
        }
    }

    public function testScopesDoNotImplyEachOther(): void
    {
        $lifecycle = $this->token([ApiScope::Lifecycle]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($lifecycle, ApiScope::LifecycleDestroy, 'blog'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($lifecycle, ApiScope::Read, 'blog'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($this->token([ApiScope::Deploy]), ApiScope::Lifecycle, 'blog'));
    }

    public function testAnotherProjectIsRefused(): void
    {
        $token = $this->token([ApiScope::Read, ApiScope::Deploy], ['blog']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($token, ApiScope::Read, 'shop'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($token, ApiScope::Deploy, 'blog-2'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($token, ApiScope::Deploy, 'BLOG'));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->scopeVote($token, ApiScope::Deploy, 'blog'));
    }

    public function testTheWildcardIsHonouredForAReadOnlyTokenOnly(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->scopeVote($this->token([ApiScope::Read], ['*']), ApiScope::Read, 'anything'));
        // A row that got past the issuing rules (hand-edited database) still does not widen a writing token.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($this->token([ApiScope::Read, ApiScope::Deploy], ['*']), ApiScope::Deploy, 'anything'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($this->token([ApiScope::Read, ApiScope::Deploy], ['*']), ApiScope::Read, 'anything'));
    }

    public function testAnUnknownScopeInTheDatabaseGrantsNothing(): void
    {
        $entity = new ApiToken('t', '', str_repeat('a', 64), 'abcdefgh', ['root', '*', 'admin'], ['blog'], [], [], 'cli', new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'));
        $user = ApiTokenUser::fromToken($entity);

        self::assertSame([], $user->scopes());
    }

    public function testAPersonIsNeverATokenAndATokenIsNeverAPerson(): void
    {
        $person = new UsernamePasswordToken(new FleetUser('alice', 'Alice', null, ['fleet-admins'], 'fleet-admins'), 'main', ['ROLE_ADMIN']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->scopeVote($person, ApiScope::Read, 'blog'));

        $token = $this->token([ApiScope::Read, ApiScope::Deploy, ApiScope::Lifecycle, ApiScope::LifecycleDestroy], ['blog']);
        self::assertSame(['ROLE_API'], $token->getRoleNames());
    }

    public function testProjectVoterGivesATokenOnlyWhatItsScopesAndProjectsAllow(): void
    {
        $blog = new Project('blog', 'https://git.example.com/blog.git');
        $shop = new Project('shop', 'https://git.example.com/shop.git');

        $reader = $this->token([ApiScope::Read]);
        foreach ([ProjectVoter::OPERATE, ProjectVoter::DEPLOY, ProjectVoter::DESTROY, ProjectVoter::SETTINGS] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->projectVote($reader, $attribute, $blog), 'read only: '.$attribute);
        }

        $deployer = $this->token([ApiScope::Deploy]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->projectVote($deployer, ProjectVoter::DEPLOY, $blog));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->projectVote($deployer, ProjectVoter::DEPLOY, $shop));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->projectVote($deployer, ProjectVoter::OPERATE, $blog));

        $lifecycle = $this->token([ApiScope::Lifecycle]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->projectVote($lifecycle, ProjectVoter::OPERATE, $blog));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->projectVote($lifecycle, ProjectVoter::DESTROY, $blog), 'destroy needs its flag');
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->projectVote($lifecycle, ProjectVoter::OPERATE, $shop));

        $destroyer = $this->token([ApiScope::Lifecycle, ApiScope::LifecycleDestroy]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->projectVote($destroyer, ProjectVoter::DESTROY, $blog));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->projectVote($destroyer, ProjectVoter::DESTROY, $shop));

        // Settings (create, edit, delete projects, templates, ports) are people's: no token reaches them.
        $everything = $this->token([ApiScope::Read, ApiScope::Deploy, ApiScope::Lifecycle, ApiScope::LifecycleDestroy]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->projectVote($everything, ProjectVoter::SETTINGS, $blog));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->projectVote($everything, ProjectVoter::SETTINGS, null));
    }
}
