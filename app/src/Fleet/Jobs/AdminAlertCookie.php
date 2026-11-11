<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives an administrator's page the Mercure cookie that lets the shell hear the terminal write alert (FLE-289), when the
 * page did not set one for its own topics already (`LiveFeedCookie::attach` adds the alert topic to those). Full HTML page
 * loads only: never a Turbo frame, a JSON answer or a redirect.
 */
final readonly class AdminAlertCookie
{
    public function __construct(private LiveFeedCookie $cookie)
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !$request->isMethodSafe() || $request->headers->has('Turbo-Frame')
            || 200 !== $response->getStatusCode() || !self::isHtml($response)) {
            return;
        }
        if ($request->attributes->get(LiveFeedCookie::ATTACHED, false)) {
            return;
        }
        $this->cookie->attachShell($request, $response);
    }

    /** Symfony fills in `text/html` only when the response is prepared, after this event: no header yet means HTML. */
    private static function isHtml(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type');

        return '' === $type || str_starts_with($type, 'text/html');
    }
}
