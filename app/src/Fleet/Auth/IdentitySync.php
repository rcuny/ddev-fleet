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
     * @throws \RuntimeException|\InvalidArgumentException from $change or the render: nothing was committed
     * @throws UserChangeRefused                           a LastAdminGuard refusal thrown by $change: nothing was committed
     * @throws AccessInstallFailed                         the access install after the commit failed: the change IS
     *                                                     saved (the exception carries $change's result) and
     *                                                     refreshAccess() retries the install
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
        try {
            $this->access->sync();
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            throw new AccessInstallFailed($result, $e);
        }

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
