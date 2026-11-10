<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Http\ProblemResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * An authenticated token that failed an access rule (the firewall's access_control). Controllers refuse with the
 * precise reason through ApiAuthorizer; this is the backstop for a rule that fires before any controller.
 */
final class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return ProblemResponse::create($request, Response::HTTP_FORBIDDEN, 'forbidden', 'This token may not do that.');
    }
}
