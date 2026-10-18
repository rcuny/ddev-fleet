<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\HealthChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(private readonly HealthChecker $checker)
    {
    }

    /** Public, unauthenticated; reports only pass/fail per check, never details. */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $report = $this->checker->check();

        $response = new JsonResponse(
            $report->toArray(),
            $report->isOk() ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
