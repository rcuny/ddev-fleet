<?php

declare(strict_types=1);

namespace App\Api\Token;

use App\Domain\ApiToken\ApiToken;

/** A freshly issued token: the secret is available here and nowhere else, so show it once. */
final readonly class IssuedToken
{
    public function __construct(public ApiToken $token, public string $secret)
    {
    }
}
