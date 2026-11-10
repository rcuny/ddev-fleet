<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Http\ApiProblem;
use App\Api\Http\ClientAddress;
use App\Api\Http\ProblemResponse;
use App\Fleet\Audit\AuditLog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Every unauthenticated or refused API request ends here: a problem+json 401 that never says why (unknown, expired,
 * revoked, malformed and missing tokens look alike), or a 429 once the client address is over its failure limit.
 * The reason goes to the audit log, within the same limit so a flood cannot fill the table.
 */
final readonly class ApiEntryPoint implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(private ApiAuthFailureThrottle $throttle, private AuditLog $audit)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->refuse($request, $authException);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->refuse($request, $exception);
    }

    private function refuse(Request $request, ?AuthenticationException $exception): Response
    {
        $address = ClientAddress::of($request);
        $reason = $exception instanceof ApiTokenRejected ? $exception->reason() : 'no_credentials';

        if (ApiTokenRejected::THROTTLED === $reason) {
            return ProblemResponse::fromProblem($request, ApiProblem::rateLimited($this->throttle->retryAfter($address)));
        }

        if ($this->throttle->recordFailure($address)) {
            $tokenId = $exception instanceof ApiTokenRejected ? $exception->tokenId() : null;
            // A proxy identity header is never an identity here; its presence is worth a line for whoever reads the log.
            $note = $request->headers->has('Remote-User') ? ' remote-user-header-ignored' : '';
            $this->audit->record(
                null === $tokenId ? 'api' : 'token:'.$tokenId,
                AuditLog::API_DENIED,
                \sprintf('auth:%s ip=%s%s', $reason, $address, $note),
            );
        }

        return ProblemResponse::fromProblem($request, ApiProblem::unauthorized());
    }
}
