<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The guard in front of every request to the wizard (decision: SetupGate). It runs twice:
 *
 *  1. before routing, on the decoded and normalised path, so an odd spelling of /setup (`/%73etup/admin`, `/Setup`,
 *     `//setup`, double encoding) cannot get past, and a method the router would refuse with 405 does not reveal the
 *     wizard either;
 *  2. right after routing, on the matched route: any route whose name starts with `setup_` is guarded even if its path
 *     was not recognised by 1 (a route added later under another prefix is still covered).
 *
 * Both run before the firewall, so the answer does not depend on any identity header. Responses to the wizard are
 * never cached or indexed and leak no referrer. The controllers ask the gate again themselves.
 */
final readonly class SetupAccessSubscriber implements EventSubscriberInterface
{
    public const string ENTER_ROUTE = 'setup_enter';
    public const string ROUTE_PREFIX = 'setup_';

    private const string CHECKED = '_setup_gate_checked';

    public function __construct(private SetupGate $gate)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Routing is 32, the firewall 8.
            KernelEvents::REQUEST => [['beforeRouting', 64], ['afterRouting', 20]],
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function beforeRouting(RequestEvent $event): void
    {
        if ($event->isMainRequest() && SetupGate::covers($event->getRequest())) {
            $this->check($event);
        }
    }

    public function afterRouting(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && !$request->attributes->has(self::CHECKED) && self::isSetupRoute($request)) {
            $this->check($event);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !(SetupGate::covers($request) || self::isSetupRoute($request))) {
            return;
        }
        $response = $event->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }

    private function check(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $request->attributes->set(self::CHECKED, true);

        $refusal = $this->gate->refusal($request);
        if (null !== $refusal) {
            $event->setResponse($refusal);
        }
    }

    private static function isSetupRoute(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) && str_starts_with($route, self::ROUTE_PREFIX);
    }
}
