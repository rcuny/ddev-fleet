<?php

declare(strict_types=1);

namespace App\Api\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds `application/problem+json` (RFC 9457) responses: {type, title, status, detail, code, request_id}.
 */
final class ProblemResponse
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function create(Request $request, int $status, string $code, string $detail, array $headers = []): JsonResponse
    {
        $response = new JsonResponse([
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'request_id' => ApiRequestId::of($request),
        ], $status, $headers);
        $response->headers->set('Content-Type', 'application/problem+json');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public static function fromProblem(Request $request, ApiProblem $problem): JsonResponse
    {
        return self::create($request, $problem->getStatusCode(), $problem->problemCode(), $problem->getMessage(), $problem->getHeaders());
    }
}
