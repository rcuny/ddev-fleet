<?php

declare(strict_types=1);

namespace App\Security\Api;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scope and project checks for API tokens. A token passes only when it holds the scope and, when a project is
 * named, lists that project. Anything that is not an ApiTokenUser is refused here, so a person's role can
 * never satisfy an API scope check either.
 *
 * @extends Voter<string, ApiScopeRequest>
 */
final class ScopeVoter extends Voter
{
    public const string ATTRIBUTE = 'API_SCOPE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute && $subject instanceof ApiScopeRequest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof ApiTokenUser) {
            return false;
        }

        return $user->hasScope($subject->scope)
            && (null === $subject->project || $user->allowsProject($subject->project));
    }
}
