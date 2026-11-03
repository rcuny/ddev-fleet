<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * The answer to a webhook call: an HTTP status and the JSON object to send.
 */
final readonly class ReceiveResult
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(public int $status, public array $body)
    {
    }

    public static function error(int $status, string $message): self
    {
        return new self($status, ['error' => $message]);
    }
}
