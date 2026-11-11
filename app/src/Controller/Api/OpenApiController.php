<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Domain\ApiToken\ApiScope;
use App\Security\Api\ApiAuthorizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * `GET /api/v1/openapi.json` (scope `read`): the hand-maintained OpenAPI 3.1 document (`resources/api/openapi.yaml`)
 * as JSON. Any read token may fetch it; it describes the API, never the host. The contract test keeps it in step
 * with the routes.
 */
final readonly class OpenApiController
{
    public function __construct(
        private ApiAuthorizer $authorizer,
        #[Autowire('%kernel.project_dir%/resources/api/openapi.yaml')]
        private string $specFile,
    ) {
    }

    #[Route('/api/v1/openapi.json', name: 'api_v1_openapi', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $this->authorizer->require(ApiScope::Read);
        try {
            $document = Yaml::parseFile($this->specFile);
        } catch (\Throwable) {
            throw ApiProblem::notFound('The API description is not available on this host.');
        }

        return new JsonResponse($document);
    }
}
