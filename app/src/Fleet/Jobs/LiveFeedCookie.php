<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;

/**
 * Lets the page subscribe to private Mercure topics: the cookie carries a token scoped to exactly those topics. A hub
 * that is not configured only costs the page its live updates (it polls), never the page itself.
 */
final readonly class LiveFeedCookie
{
    public function __construct(private Authorization $authorization)
    {
    }

    /**
     * @param list<string> $topics
     */
    public function attach(Request $request, Response $response, array $topics): void
    {
        try {
            $response->headers->setCookie($this->authorization->createCookie($request, $topics));
        } catch (\Throwable) {
            // No cookie: the page falls back to polling.
        }
    }
}
