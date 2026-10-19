<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Domain\Host\HostSettingsRepository;
use App\Setup\SetupAccessSubscriber;
use App\Setup\SetupAttemptLimiter;
use App\Setup\SetupCookie;
use App\Setup\SetupGate;
use App\Setup\SetupProgress;
use App\Setup\SetupRefused;
use App\Setup\SetupState;
use App\Setup\SetupTokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The entry: `/setup?token=<one-time token>` swaps the token for a signed session cookie and redirects to a clean URL,
 * so the token does not stay in the address bar, history or a Referer. With a valid cookie and no token it forwards to
 * the first step that still needs doing; anything else is the one fixed 403.
 */
final class EnterController extends AbstractSetupController
{
    public function __construct(
        private readonly SetupState $state,
        private readonly SetupTokenService $tokens,
        private readonly SetupCookie $cookies,
        private readonly SetupAttemptLimiter $limiter,
        private readonly SetupProgress $progress,
        private readonly HostSettingsRepository $settings,
    ) {
    }

    // No method restriction: a router 405 (with its Allow header) would answer before the guard could.
    #[Route('/setup', name: SetupAccessSubscriber::ENTER_ROUTE)]
    public function __invoke(Request $request): Response
    {
        // The subscriber has normally answered already; the token exchange itself is let through while setup is open.
        if (null !== $refusal = $this->guard($request)) {
            return $refusal;
        }
        if (!$request->isMethod('GET')) {
            return $this->denied();
        }

        $token = $request->query->all()['token'] ?? null;
        if (\is_string($token) && '' !== $token) {
            return $this->exchange($request, $token);
        }
        if ($this->state->permits(SetupGate::cookie($request))) {
            return $this->redirectToRoute($this->progress->firstIncomplete()->route());
        }

        return $this->denied();
    }

    private function exchange(Request $request, #[\SensitiveParameter] string $token): Response
    {
        // Under php_fastcgi REMOTE_ADDR is the real client (Caddy is the only peer, and it passes the client address).
        $client = $request->server->get('REMOTE_ADDR');
        if (!$this->limiter->attempt(\is_string($client) && '' !== $client ? $client : 'unknown')) {
            $response = $this->denied(Response::HTTP_TOO_MANY_REQUESTS, throttled: true);
            $response->headers->set('Retry-After', '900');

            return $response;
        }
        if (!$this->tokens->verify($token)) {
            return $this->denied();
        }

        $host = $this->settings->get();
        try {
            $cookie = null === $host ? throw new SetupRefused('The host is not initialised yet.') : $this->cookies->issue($host);
        } catch (SetupRefused) {
            return $this->denied();
        }

        $target = $this->progress->firstIncomplete();
        if ('cross-site' === $request->headers->get('Sec-Fetch-Site')) {
            // The link came from another site (a webmail or chat page). A SameSite=Strict cookie is not sent on a
            // redirect chain that began there, so a 302 would land on the 403. A page of ours, left by a click, is
            // same-site.
            $response = $this->page('setup/continue.html.twig', $target, ['target' => $target]);
        } else {
            $response = $this->redirectToRoute($target->route());
        }
        $response->headers->setCookie($cookie);

        return $response;
    }
}
