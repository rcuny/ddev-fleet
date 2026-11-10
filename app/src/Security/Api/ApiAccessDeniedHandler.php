<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Http\ClientAddress;
use App\Api\Http\ProblemResponse;
use App\Fleet\Audit\AuditLog;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * An authenticated token that failed an access rule (the firewall's access_control). Controllers refuse with the
 * precise reason through ApiAuthorizer; this is the backstop for a rule that fires before any controller, and it
 * audits the refusal like ApiAuthorizer does.
 */
final readonly class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private Security $security,
        private AuditLog $audit,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        $user = $this->security->getUser();
        $this->audit->record(
            $user instanceof ApiTokenUser ? $user->getUserIdentifier() : 'api:anonymous',
            AuditLog::API_DENIED,
            \sprintf('access_control:%s ip=%s', $request->getPathInfo(), ClientAddress::of($request)),
        );

        return ProblemResponse::create($request, Response::HTTP_FORBIDDEN, 'forbidden', 'This token may not do that.');
    }
}
