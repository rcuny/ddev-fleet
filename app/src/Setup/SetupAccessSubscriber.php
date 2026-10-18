<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * The one guard in front of every path under /setup, run right after routing (before the firewall, so the answer does
 * not depend on any identity header):
 *
 *  - setup complete, or the host not initialised: 404, as if the wizard did not exist;
 *  - setup open and no valid session cookie: 403 with one fixed body, whatever was wrong (no cookie, a forged one, an
 *    expired one, one from an older token), so the response is no oracle;
 *  - the token exchange (route setup_enter) is let through while setup is open: it checks the token itself.
 *
 * Responses under /setup are never cached or indexed and leak no referrer.
 */
final readonly class SetupAccessSubscriber implements EventSubscriberInterface
{
    public const string ENTER_ROUTE = 'setup_enter';

    public function __construct(
        private SetupState $state,
        private Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Routing is 32, the firewall 8.
            KernelEvents::REQUEST => ['onRequest', 20],
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::guards($request)) {
            return;
        }

        if (!$this->state->isOpen()) {
            throw new NotFoundHttpException();
        }
        if (self::ENTER_ROUTE === $request->attributes->get('_route')) {
            return;
        }
        if (!$this->state->permits(self::cookie($request))) {
            $event->setResponse(new Response($this->twig->render('setup/denied.html.twig'), Response::HTTP_FORBIDDEN));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !self::guards($event->getRequest())) {
            return;
        }
        $response = $event->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }

    public static function guards(Request $request): bool
    {
        $path = $request->getPathInfo();

        return '/setup' === $path || str_starts_with($path, '/setup/');
    }

    public static function cookie(Request $request): ?string
    {
        $value = $request->cookies->all()[SetupCookie::NAME] ?? null;

        return \is_string($value) ? $value : null;
    }
}
