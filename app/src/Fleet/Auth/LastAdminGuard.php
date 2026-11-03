<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\User\User;
use App\Domain\User\UserRepository;

/**
 * Keeps the fleet reachable: nobody may delete, disable or demote the last enabled administrator, nor (when the caller
 * knows who is acting, as the pages do) themselves. The commands have no acting user and pass null.
 *
 * Call it before the change, inside or outside IdentitySync::commit().
 */
final readonly class LastAdminGuard
{
    public function __construct(
        private UserRepository $users,
        private AdminGroup $admins,
    ) {
    }

    /**
     * @throws UserChangeRefused
     */
    public function assertMayDelete(User $user, ?string $actingUsername = null): void
    {
        $this->assert($user, 'deleted', $actingUsername);
    }

    /**
     * @throws UserChangeRefused
     */
    public function assertMayDisable(User $user, ?string $actingUsername = null): void
    {
        $this->assert($user, 'disabled', $actingUsername);
    }

    /**
     * @throws UserChangeRefused
     */
    public function assertMayDemote(User $user, ?string $actingUsername = null): void
    {
        $this->assert($user, 'removed from the administrators', $actingUsername);
    }

    private function assert(User $user, string $what, ?string $actingUsername): void
    {
        if (null !== $actingUsername && $actingUsername === $user->username()) {
            throw new UserChangeRefused(\sprintf('Your own account cannot be %s.', $what));
        }
        if ($user->isEnabled() && $this->admins->includes($user) && $this->users->countInGroup($this->admins->name()) <= 1) {
            throw new UserChangeRefused(\sprintf('"%s" is the last enabled administrator and cannot be %s.', $user->username(), $what));
        }
    }
}
