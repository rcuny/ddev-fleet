<?php

declare(strict_types=1);

namespace App\Api\Http;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An API error that becomes an RFC 9457 `application/problem+json` response. `code` is the machine-readable
 * reason (`token_expired`, `scope_missing`, ...); the message is the human `detail` and must be safe to show.
 */
final class ApiProblem extends \RuntimeException implements HttpExceptionInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly string $problemCode,
        string $detail,
        private readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }

    public static function unauthorized(): self
    {
        // Never says why: an unknown, expired, revoked or malformed token all look alike.
        return new self(401, 'unauthorized', 'A valid API token is required.', ['WWW-Authenticate' => 'Bearer realm="fleet"']);
    }

    public static function scopeMissing(string $scope): self
    {
        return new self(403, 'scope_missing', \sprintf('This token lacks the "%s" scope.', $scope));
    }

    public static function projectNotAllowed(string $project): self
    {
        return new self(403, 'project_not_allowed', \sprintf('This token cannot access the project "%s".', $project));
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Too many requests.', ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function problemCode(): string
    {
        return $this->problemCode;
    }
}
