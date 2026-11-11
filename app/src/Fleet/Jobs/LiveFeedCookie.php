<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Api\Terminal\TerminalWriteNotifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Lets the page subscribe to private Mercure topics: the cookie carries a token scoped to exactly those topics. A hub
 * that is not configured only costs the page its live updates (it polls), never the page itself.
 */
final readonly class LiveFeedCookie
{
    /** Request attribute: this request's page already set its Mercure cookie. */
    public const string ATTACHED = '_fleet_live_feed_cookie';

    public function __construct(private Authorization $authorization, private AuthorizationCheckerInterface $auth)
    {
    }

    /**
     * An administrator's cookie always also carries the terminal write alert topic (FLE-289): the browser holds one
     * Mercure cookie, so every page that sets it must keep that topic or the admin shell would lose the alert.
     *
     * @param list<string> $topics
     */
    public function attach(Request $request, Response $response, array $topics): void
    {
        $request->attributes->set(self::ATTACHED, true);
        if ($this->isAdmin()) {
            $topics[] = TerminalWriteNotifier::ALERT_TOPIC;
        }
        try {
            $response->headers->setCookie($this->authorization->createCookie($request, $topics));
        } catch (\Throwable) {
            // No cookie: the page falls back to polling.
        }
    }

    /** The cookie of the alert-only pages: nothing else subscribes there, and only administrators get one. */
    public function attachShell(Request $request, Response $response): void
    {
        if ($this->isAdmin()) {
            $this->attach($request, $response, []);
        }
    }

    public function isAdmin(): bool
    {
        try {
            return $this->auth->isGranted('ROLE_ADMIN');
        } catch (\Throwable) {
            return false;
        }
    }
}
