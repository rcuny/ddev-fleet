<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * A user asked for more secret reveals than the throttle allows; nothing was opened.
 */
final class RevealThrottled extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many reveals. Try again later.');
    }
}
