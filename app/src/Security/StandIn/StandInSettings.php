<?php

declare(strict_types=1);

namespace App\Security\StandIn;

use App\Security\FleetUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Development/test only: the identities the stand-in can impersonate (values from FLEET_STAND_IN_*).
 */
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class StandInSettings
{
    public const string COOKIE = 'fleet_stand_in_role';
    public const string HEADER = 'X-Fleet-Stand-In-Role';
    public const string ADMIN = 'admin';
    public const string MEMBER = 'member';
    public const string NONE = 'none';
    public const array ROLES = [self::ADMIN, self::MEMBER, self::NONE];

    private string $defaultRole;

    public function __construct(
        #[Autowire(env: 'FLEET_STAND_IN_ROLE')]
        string $defaultRole,
        #[Autowire(env: 'FLEET_STAND_IN_ADMIN_USER')]
        private string $adminUser,
        #[Autowire(env: 'FLEET_STAND_IN_ADMIN_GROUPS')]
        private string $adminGroups,
        #[Autowire(env: 'FLEET_STAND_IN_MEMBER_USER')]
        private string $memberUser,
        #[Autowire(env: 'FLEET_STAND_IN_MEMBER_GROUPS')]
        private string $memberGroups,
        #[Autowire('%fleet.admin_group%')]
        private string $adminGroup,
    ) {
        // An unusable default fails closed.
        $this->defaultRole = self::known($defaultRole) ?? self::NONE;
    }

    public static function isRole(?string $value): bool
    {
        return null !== self::known($value);
    }

    private static function known(?string $value): ?string
    {
        return null !== $value && \in_array($value, self::ROLES, true) ? $value : null;
    }

    public function defaultRole(): string
    {
        return $this->defaultRole;
    }

    /**
     * Header wins over cookie, cookie over the env default; an unknown value counts as absent.
     */
    public function resolveRole(?string $header, ?string $cookie): string
    {
        return self::known($header) ?? self::known($cookie) ?? $this->defaultRole;
    }

    public function userFor(string $role): ?FleetUser
    {
        return match ($role) {
            self::ADMIN => $this->build($this->adminUser, 'Dev Admin', $this->adminGroups),
            self::MEMBER => $this->build($this->memberUser, 'Dev Member', $this->memberGroups),
            default => null,
        };
    }

    private function build(string $user, string $displayName, string $groupsCsv): FleetUser
    {
        if ('' === $user) {
            throw new \LogicException('A stand-in user name must not be empty.');
        }
        $groups = array_values(array_filter(array_map(trim(...), explode(',', $groupsCsv)), static fn (string $g): bool => '' !== $g));

        return new FleetUser($user, $displayName, $user.'@fleet.example.com', $groups, $this->adminGroup);
    }
}
