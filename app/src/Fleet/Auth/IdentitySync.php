<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\User\UserRepository;

/**
 * The one way to change users and project memberships so the sign-in service follows. A change runs in a database
 * transaction that also checks the users render: a user that cannot be rendered commits nothing. Only after the commit
 * is users.yml written (with the Authelia hash read-back, see AutheliaUsersFile), so a file never holds a change that
 * was rolled back, and the transaction does not hold the database write lock while files are written. Project access
 * is part of that file (the `fleet-project-<name>` groups, ProjectGroup), which Authelia reloads live: nothing is
 * restarted and no session ends.
 *
 * The pages and the `fleet user` commands call commit() around UserService / ProjectAccessService calls.
 */
final readonly class IdentitySync
{
    public function __construct(
        private UserRepository $users,
        private AutheliaUsersFile $usersFile,
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
     * @throws AccessInstallFailed                         writing users.yml or installing the access rules after the
     *                                                     commit failed: the change IS saved (the exception carries
     *                                                     $change's result) and the next change or refreshAccess()
     *                                                     retries
     */
    public function commit(callable $change): mixed
    {
        $result = $this->users->transactional(function () use ($change): mixed {
            $result = $change();
            // allFresh() reloads the users from the database and would drop a pending change: write it first.
            $this->users->flush();
            $this->usersFile->check($this->users);

            return $result;
        });
        try {
            $this->usersFile->sync($this->users);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            throw new AccessInstallFailed($result, $e);
        }

        return $result;
    }

    /**
     * Rewrites users.yml from the stored users and memberships (after a project was deleted, its group goes).
     *
     * @return bool true when the file changed
     */
    public function refreshAccess(): bool
    {
        return $this->usersFile->sync($this->users);
    }
}
