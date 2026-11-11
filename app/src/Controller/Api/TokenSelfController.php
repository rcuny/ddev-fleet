<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\Api\ApiAuthorizer;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/tokens/self`: the caller's own token (scopes, restrictions, expiry, last use), so a client can check
 * its health before it expires. Any valid token may ask; the secret and its hash are never returned.
 */
final readonly class TokenSelfController
{
    public function __construct(private ApiAuthorizer $authorizer, private ClockInterface $clock)
    {
    }

    #[Route('/api/v1/tokens/self', name: 'api_v1_tokens_self', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $token = $this->authorizer->token()->token();
        $now = $this->clock->now();

        return new JsonResponse([
            'id' => $token->id(),
            'name' => $token->name(),
            'description' => $token->description(),
            'prefix_hint' => $token->prefixHint(),
            'scopes' => $token->scopes(),
            'projects' => $token->projects(),
            'instance_globs' => $token->instanceGlobs(),
            'ip_allow' => $token->ipAllow(),
            'created_by' => $token->createdBy(),
            'created_at' => $token->createdAt()->format(\DATE_ATOM),
            'expires_at' => $token->expiresAt()->format(\DATE_ATOM),
            'expires_in_seconds' => max(0, $token->expiresAt()->getTimestamp() - $now->getTimestamp()),
            'last_used_at' => $token->lastUsedAt()?->format(\DATE_ATOM),
            'last_used_ip' => $token->lastUsedIp(),
            'rotated_from' => $token->rotatedFrom(),
        ]);
    }
}
