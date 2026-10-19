<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\User\UserRepository;

/**
 * The one way to change users and project memberships so the sign-in service follows. A change runs in a database
 * transaction together with the users.yml render (with the Authelia hash read-back, see AutheliaUsersFile): when the
 * render fails nothing is committed. After the commit the access rules are re-rendered and, when they changed, installed
 * (AutheliaAccessSync).
 *
 * The pages and the `fleet user` commands call commit() around UserService / ProjectAccessService calls.
 */
final readonly class IdentitySync
{
    public function __construct(
        private UserRepository $users,
        private AutheliaUsersFile $usersFile,
        private AutheliaAccessSync $access,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $change mutates users and memberships (through UserService / ProjectAccessService)
     *
     * @return T whatever $change returned
     *
     * @throws \RuntimeException|\InvalidArgumentException from $change or the render: nothing was committed. Also from
     *                                                     the access install after the commit: the change IS saved
     *                                                     and the call may be repeated (refreshAccess()).
     */
    public function commit(callable $change): mixed
    {
        $result = $this->users->transactional(function () use ($change): mixed {
            $result = $change();
            // allFresh() reloads the users from the database and would drop a pending change: write it first.
            $this->users->flush();
            $this->usersFile->sync($this->users);

            return $result;
        });
        $this->access->sync();

        return $result;
    }

    /**
     * Re-renders the access rules and installs them when they changed (see AutheliaAccessSync::sync()).
     */
    public function refreshAccess(bool $force = false): bool
    {
        return $this->access->sync($force);
    }
}
