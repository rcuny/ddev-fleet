<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Http\ApiProblem;
use App\Api\Http\ClientAddress;
use App\Domain\ApiToken\ApiScope;
use App\Fleet\Audit\AuditLog;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What API controllers call before they act: `require(Scope::Deploy, 'blog')`. It answers 403 with the precise
 * reason (the caller already holds a valid token) and audits every refusal.
 */
final readonly class ApiAuthorizer
{
    public function __construct(
        private Security $security,
        private AuditLog $audit,
        private RequestStack $requests,
    ) {
    }

    /** The calling token. 401 when the request is not authenticated as one (a controller outside the firewall). */
    public function token(): ApiTokenUser
    {
        $user = $this->security->getUser();
        if (!$user instanceof ApiTokenUser) {
            throw ApiProblem::unauthorized();
        }

        return $user;
    }

    /**
     * @throws ApiProblem 403 `scope_missing` or `project_not_allowed`
     */
    public function require(ApiScope $scope, ?string $project = null): ApiTokenUser
    {
        $user = $this->token();
        if ($this->security->isGranted(ScopeVoter::ATTRIBUTE, new ApiScopeRequest($scope, $project))) {
            return $user;
        }

        $problem = $user->hasScope($scope) && null !== $project
            ? ApiProblem::projectNotAllowed($project)
            : ApiProblem::scopeMissing($scope->value);
        $request = $this->requests->getCurrentRequest();
        $this->audit->record(
            $user->getUserIdentifier(),
            AuditLog::API_DENIED,
            \sprintf('%s:%s%s ip=%s', $problem->problemCode(), $scope->value, null === $project ? '' : ' project='.$project, null === $request ? 'unknown' : ClientAddress::of($request)),
        );

        throw $problem;
    }
}
