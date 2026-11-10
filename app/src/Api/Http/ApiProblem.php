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
     * @param array<string, mixed>  $extra   more members of the problem document (`errors`, `verdict`)
     */
    public function __construct(
        private readonly int $status,
        private readonly string $problemCode,
        string $detail,
        private readonly array $headers = [],
        private readonly array $extra = [],
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

    /** Host-wide data (host, capacity, upgrade) is for a token that sees every project. */
    public static function hostWide(): self
    {
        return new self(403, 'host_wide_not_allowed', 'This token is limited to some projects and cannot read host-wide data.');
    }

    public static function notFound(string $detail = 'No such resource.'): self
    {
        return new self(404, 'not_found', $detail);
    }

    /** A query parameter or body field the API cannot accept (`invalid_cursor`, `invalid_filter`, ...). */
    public static function invalid(string $code, string $detail): self
    {
        return new self(400, $code, $detail);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Too many requests.', ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    /** @param array<string, string> $errors field => message */
    public static function validation(array $errors): self
    {
        return new self(422, 'validation_failed', 'Some values were refused.', [], ['errors' => $errors]);
    }

    /** The request is valid but not allowed in the current state (`instance_busy`, `protected_instance`, ...). */
    public static function conflict(string $code, string $detail): self
    {
        return new self(409, $code, $detail);
    }

    public static function ifMatchRequired(): self
    {
        return new self(428, 'if_match_required', 'Destroying needs an If-Match header with the instance\'s created_at, so a stale id never removes a newer instance.');
    }

    public static function staleIfMatch(): self
    {
        return new self(412, 'stale_if_match', 'The instance changed since the If-Match value was read (it was recreated); nothing was done.');
    }

    /** @param array<string, mixed> $verdict */
    public static function capacityFull(array $verdict): self
    {
        $reasons = \is_array($verdict['reasons'] ?? null) ? implode(', ', array_map('strval', $verdict['reasons'])) : 'unknown';

        return new self(429, 'capacity_full', 'No capacity for another run: '.$reasons.'.', ['Retry-After' => '120'], ['verdict' => $verdict]);
    }

    /**
     * @return array<string, mixed>
     */
    public function extra(): array
    {
        return $this->extra;
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
