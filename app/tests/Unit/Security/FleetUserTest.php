<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\FleetUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FleetUserTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function groupsToRoles(): iterable
    {
        yield 'admin group' => [['fleet-admins'], ['ROLE_USER', 'ROLE_ADMIN']];
        yield 'admin among others' => [['demo', 'fleet-admins'], ['ROLE_USER', 'ROLE_ADMIN']];
        yield 'other group' => [['demo'], ['ROLE_USER', 'ROLE_MEMBER']];
        yield 'no groups' => [[], ['ROLE_USER', 'ROLE_MEMBER']];
        yield 'case differs' => [['Fleet-Admins'], ['ROLE_USER', 'ROLE_MEMBER']];
        yield 'substring is not a match' => [['fleet-admins-extra'], ['ROLE_USER', 'ROLE_MEMBER']];
    }

    /**
     * @param list<string> $groups
     * @param list<string> $roles
     */
    #[DataProvider('groupsToRoles')]
    public function testRolesFollowTheAdminGroup(array $groups, array $roles): void
    {
        self::assertSame($roles, new FleetUser('alice', 'Alice', null, $groups, 'fleet-admins')->getRoles());
    }

    public function testASiteOnlyUserHasNeitherMemberNorAdminRole(): void
    {
        $user = new FleetUser('shield', 'shield', null, ['fleet-site-only', 'fleet-project-blog', 'fleet-admins'], 'fleet-admins');

        self::assertTrue($user->isSiteOnly());
        self::assertSame(['ROLE_USER', 'ROLE_SITE_ONLY'], $user->getRoles(), 'not even with the admin group');
        self::assertFalse(new FleetUser('a', 'a', null, ['demo'], 'fleet-admins')->isSiteOnly());
    }

    public function testAccessors(): void
    {
        $user = new FleetUser('alice', 'Alice A.', 'alice@fleet.example.com', ['demo'], 'fleet-admins');

        self::assertSame('alice', $user->getUserIdentifier());
        self::assertSame('Alice A.', $user->displayName());
        self::assertSame('alice@fleet.example.com', $user->email());
        self::assertSame(['demo'], $user->groups());
    }

    public function testAnEmptyAdminGroupNeverGrantsAdmin(): void
    {
        self::assertSame(['ROLE_USER', 'ROLE_MEMBER'], new FleetUser('a', 'a', null, [''], '')->getRoles());
    }
}
