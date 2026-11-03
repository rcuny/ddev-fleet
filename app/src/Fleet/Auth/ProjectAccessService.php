<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\Project\ProjectAccess;
use App\Domain\Project\ProjectAccessRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Projects\NotFound;

/**
 * Who is a member of which project: the only code that creates and removes project_access rows. It touches the
 * database only; the users.yml project groups follow the rows, so after a change call IdentitySync::commit() (which
 * wraps the change and rewrites the file). Administrators need no row: the voter and the
 * Caddy sites (admin group) give them every project.
 *
 * Every method flushes once at its end, so a call is atomic on its own and also composes inside a transaction.
 */
final readonly class ProjectAccessService
{
    public function __construct(
        private UserRepository $users,
        private ProjectRepository $projects,
        private ProjectAccessRepository $access,
    ) {
    }

    /**
     * @return bool true when the membership was created; false when it already existed
     *
     * @throws UnknownUser
     * @throws NotFound
     */
    public function grant(string $username, string $projectName): bool
    {
        $user = $this->user($username);
        $project = $this->projects->findByName($projectName) ?? throw NotFound::project($projectName);
        if (null !== $this->access->find($user, $project)) {
            return false;
        }
        $this->access->add(new ProjectAccess($user, $project));
        $this->access->flush();

        return true;
    }

    /**
     * @return bool true when a membership was removed; false when there was none
     *
     * @throws UnknownUser
     * @throws NotFound
     */
    public function revoke(string $username, string $projectName): bool
    {
        $user = $this->user($username);
        $project = $this->projects->findByName($projectName) ?? throw NotFound::project($projectName);
        $row = $this->access->find($user, $project);
        if (null === $row) {
            return false;
        }
        $this->access->remove($row);
        $this->access->flush();

        return true;
    }

    /**
     * Makes the user a member of exactly these projects (the form's multi-select): adds the missing memberships and
     * removes the others. Every name is checked first, so an unknown project changes nothing.
     *
     * @param list<string> $projectNames duplicates are ignored
     *
     * @return array{added: list<string>, removed: list<string>} project names, ordered
     *
     * @throws UnknownUser
     * @throws NotFound
     */
    public function setProjects(string $username, array $projectNames): array
    {
        $user = $this->user($username);
        $wanted = [];
        foreach ($projectNames as $name) {
            $wanted[$name] = $this->projects->findByName($name) ?? throw NotFound::project($name);
        }

        $current = $this->access->projectNamesOf($user);
        $added = array_values(array_diff(array_keys($wanted), $current));
        $removed = array_values(array_diff($current, array_keys($wanted)));
        sort($added, \SORT_STRING);

        foreach ($added as $name) {
            $this->access->add(new ProjectAccess($user, $wanted[$name]));
        }
        foreach ($removed as $name) {
            $project = $this->projects->findByName($name);
            $row = null === $project ? null : $this->access->find($user, $project);
            if (null !== $row) {
                $this->access->remove($row);
            }
        }
        $this->access->flush();

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @return list<string> project names, ordered
     *
     * @throws UnknownUser
     */
    public function projectsOf(string $username): array
    {
        return $this->access->projectNamesOf($this->user($username));
    }

    /**
     * @return list<string> usernames, ordered
     *
     * @throws NotFound
     */
    public function membersOf(string $projectName): array
    {
        return $this->access->usernamesOf($this->projects->findByName($projectName) ?? throw NotFound::project($projectName));
    }

    private function user(string $username): User
    {
        return $this->users->findByUsername($username) ?? throw UnknownUser::named($username);
    }
}
