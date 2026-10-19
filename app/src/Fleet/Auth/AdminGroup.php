<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one place that knows which group makes a user an administrator: FLEET_ADMIN_GROUP (parameter
 * `fleet.admin_group`, default `fleet-admins`). The wizard puts its administrator in this group (and so into Authelia's
 * users.yml), Authelia sends it back in Remote-Groups, and RemoteUserAuthenticator grants ROLE_ADMIN to it: all three must
 * agree, or the administrator signs in without an admin shell and cannot get back in.
 */
final readonly class AdminGroup
{
    /** What Authelia can carry as a group and RemoteUserAuthenticator accepts in Remote-Groups (comma separated). */
    private const string PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    private string $name;

    public function __construct(#[Autowire('%fleet.admin_group%')] string $name)
    {
        if (1 !== preg_match(self::PATTERN, $name)) {
            throw new \InvalidArgumentException('FLEET_ADMIN_GROUP must be 1 to 128 characters: letters, digits, dot, underscore or dash, starting with a letter or digit.');
        }
        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function includes(User $user): bool
    {
        return $user->isInGroup($this->name);
    }

    /**
     * @param list<string> $groups
     *
     * @return list<string> the groups plus the admin group, once
     */
    public function addedTo(array $groups): array
    {
        return \in_array($this->name, $groups, true) ? $groups : [...$groups, $this->name];
    }
}
