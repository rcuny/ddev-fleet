<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectMembership;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may touch a project. Defence in depth behind the `^/admin` access rule: controllers and components
 * ask this voter, so a route that leaves `/admin` later does not silently open project data to members.
 *
 * @extends Voter<string, Project|null>
 */
final class ProjectVoter extends Voter
{
    /** View, edit or delete project settings, templates and ports; subject a Project, or null for list/create. Admin only. */
    public const string SETTINGS = 'PROJECT_SETTINGS';

    /** Operate the instances of a project (list, open, logs, start, stop, redeploy); subject a Project is required. Admin, or a member of it. */
    public const string OPERATE = 'PROJECT_OPERATE';

    /** Deploy instances of a project (or any project when the subject is null, e.g. the deploy form). Admin only. */
    public const string DEPLOY = 'PROJECT_DEPLOY';

    /** Destroy the instances of a project; subject a Project is required. Admin only. */
    public const string DESTROY = 'PROJECT_DESTROY';

    public function __construct(private readonly ProjectMembership $membership)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::SETTINGS => null === $subject || $subject instanceof Project,
            self::OPERATE, self::DESTROY => $subject instanceof Project,
            self::DEPLOY => null === $subject || $subject instanceof Project,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof FleetUser) {
            return false;
        }

        if (\in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        return self::OPERATE === $attribute
            && $subject instanceof Project
            && $this->membership->isMember($user, $subject);
    }
}
