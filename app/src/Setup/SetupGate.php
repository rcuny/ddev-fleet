<?php

declare(strict_types=1);

namespace App\Setup;

use App\Domain\Host\HostSettingsRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * The one decision about who may use the wizard, shared by the access subscriber (which asks it for every request
 * under /setup, before routing and again after it) and by every step controller (which asks it again, so no page
 * relies on a listener having run):
 *
 *  - host not initialised: 404, as if the wizard did not exist;
 *  - setup complete: a 404 whose body says setup is finished and where to sign in (no data, no oracle: the 404 itself
 *    already tells that much), for everyone, so a lost response or a double submit is not a dead end;
 *  - setup open and no valid session cookie: 403 with one fixed body, whatever was wrong;
 *  - the token exchange (GET /setup) is let through while setup is open: it checks the token itself.
 */
final readonly class SetupGate
{
    public function __construct(
        private SetupState $state,
        private HostSettingsRepository $settings,
        private Environment $twig,
    ) {
    }

    /**
     * Whether $request is addressed to the wizard. Decided on the path as the router will read it: percent-decoded
     * (repeatedly, so a double-encoded form is caught too), with repeated slashes and dot segments resolved, and
     * lower-cased. More requests match than the router would route; that only costs a 403/404 for a path that would
     * have been a 404 anyway.
     */
    public static function covers(Request $request): bool
    {
        $path = self::normalise($request->getPathInfo());

        return '/setup' === $path || str_starts_with($path, '/setup/');
    }

    /**
     * Whether $request is the token exchange itself: GET (or HEAD) of exactly /setup (percent-decoding once, as the
     * router does; `/Setup`, `/setup/` and `//setup` are not it and need a session like every other wizard path).
     */
    public static function isExchange(Request $request): bool
    {
        return '/setup' === rawurldecode($request->getPathInfo()) && $request->isMethod('GET');
    }

    /**
     * @return Response|null the refusal to send, null when the request may proceed
     *
     * @throws NotFoundHttpException when the host is not initialised
     */
    public function refusal(Request $request): ?Response
    {
        if ($this->state->isComplete()) {
            return new Response($this->twig->render('setup/complete.html.twig', ['domain' => $this->settings->get()?->domain()]), Response::HTTP_NOT_FOUND);
        }
        if (!$this->state->isOpen()) {
            throw new NotFoundHttpException();
        }
        if (self::isExchange($request)) {
            return null;
        }
        if (!$this->state->permits(self::cookie($request))) {
            return new Response($this->twig->render('setup/denied.html.twig'), Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    public static function cookie(Request $request): ?string
    {
        $value = $request->cookies->all()[SetupCookie::NAME] ?? null;

        return \is_string($value) ? $value : null;
    }

    private static function normalise(string $path): string
    {
        // Three rounds are more than any real client sends; a path that is still encoded after that cannot match
        // a route and is not ours to wave through either way, so stop decoding.
        for ($i = 0; $i < 3; ++$i) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }
            $path = $decoded;
        }

        $segments = [];
        foreach (explode('/', strtolower($path)) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
