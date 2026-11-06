<?php

declare(strict_types=1);

namespace App\Security\Reauth;

final class ReauthThrottled extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many password attempts.');
    }
}
