<?php

declare(strict_types=1);

namespace App\Security\Reauth;

use App\Security\CurrentActor;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The short window after a password re-entry in which the most sensitive actions (reveal a secret value, export with
 * sealed secrets) are allowed. It lives in the session, is bound to the user who confirmed, and ends by itself after
 * SECONDS. The identity comes from the proxy on every request, so a logout (a new session or another Remote-User)
 * never inherits an open window.
 */
final readonly class ReauthWindow
{
    /** How long a confirmed password keeps the gated actions open. */
    public const int SECONDS = 300;

    private const string KEY = 'fleet.reauth';

    public function __construct(
        private RequestStack $requests,
        private ClockInterface $clock,
        private CurrentActor $actor,
    ) {
    }

    /** Whether the signed-in user confirmed their password within the last SECONDS. */
    public function requireRecentAuth(): bool
    {
        $session = $this->session();
        $state = $session?->get(self::KEY);
        if (!\is_array($state) || !isset($state['actor'], $state['until']) || !\is_int($state['until'])) {
            return false;
        }

        return $state['actor'] === $this->actor->name() && $state['until'] > $this->clock->now()->getTimestamp();
    }

    public function open(): void
    {
        $this->session()?->set(self::KEY, [
            'actor' => $this->actor->name(),
            'until' => $this->clock->now()->getTimestamp() + self::SECONDS,
        ]);
    }

    public function close(): void
    {
        $this->session()?->remove(self::KEY);
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requests->getCurrentRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }
}
