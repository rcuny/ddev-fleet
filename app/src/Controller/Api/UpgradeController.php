<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Domain\ApiToken\ApiScope;
use App\Fleet\Upgrade\PendingMigrations;
use App\Fleet\Upgrade\UpgradeStatusReader;
use App\Security\Api\ApiAuthorizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/upgrade` (scope `read`): installed version, newest published release, whether the host is behind and
 * the migrations still to run. Read-only and offline: it reports what the host last saw and never asks the release
 * source, so an API call cannot trigger a network fetch (`upgrade:check --no-cache` does that, on the host).
 */
final readonly class UpgradeController
{
    public function __construct(
        private ApiAuthorizer $authorizer,
        private UpgradeStatusReader $status,
        private PendingMigrations $migrations,
    ) {
    }

    #[Route('/api/v1/upgrade', name: 'api_v1_upgrade', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $token = $this->authorizer->require(ApiScope::Read);
        if (!$token->unrestricted()) {
            throw ApiProblem::hostWide();
        }
        $status = $this->status->read();
        try {
            $pending = $this->migrations->count();
        } catch (\Throwable) {
            $pending = null;
        }

        return new JsonResponse([
            'installed' => ['raw' => $status->installed, 'version' => $status->installedVersion?->tag()],
            'latest' => $status->latest()?->toArray(),
            'behind' => $status->isBehind(),
            'engine' => $status->engine,
            'catalog_failed' => $status->catalog->hasFailed(),
            'pending_migrations' => $pending,
        ]);
    }
}
