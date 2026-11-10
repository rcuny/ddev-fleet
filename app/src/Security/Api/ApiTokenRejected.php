<?php

declare(strict_types=1);

namespace App\Security\Api;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * A bearer token that was refused. The reason is for the audit log only: the response never says why.
 */
final class ApiTokenRejected extends BadCredentialsException
{
    public const string MALFORMED = 'malformed';
    public const string UNKNOWN = 'unknown';
    public const string REVOKED = 'revoked';
    public const string EXPIRED = 'expired';
    public const string IP_REFUSED = 'ip_refused';
    public const string THROTTLED = 'throttled';

    public function __construct(private readonly string $reason, private readonly ?int $tokenId = null)
    {
        parent::__construct('Invalid token.');
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function tokenId(): ?int
    {
        return $this->tokenId;
    }
}
