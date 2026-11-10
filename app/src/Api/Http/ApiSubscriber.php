<?php

declare(strict_types=1);

namespace App\Api\Http;

use App\Security\Api\ApiTokenUser;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Everything under /api/ speaks the same way: an X-Request-Id and no-store on every response, a per-token rate
 * limit (reads and writes have their own pool), and every failure as RFC 9457 problem+json, never HTML.
 */
final class ApiSubscriber implements EventSubscriberInterface
{
    public const string PREFIX = '/api/';

    public function __construct(
        #[Autowire(service: 'limiter.api_read')]
        private readonly RateLimiterFactoryInterface $readLimiter,
        #[Autowire(service: 'limiter.api_write')]
        private readonly RateLimiterFactoryInterface $writeLimiter,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the firewall (priority 8) has authenticated the token.
            KernelEvents::REQUEST => ['onRequest', 4],
            KernelEvents::EXCEPTION => ['onException', 16],
            KernelEvents::RESPONSE => ['onResponse', -16],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !self::isApi($event->getRequest())) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof ApiTokenUser) {
            return;
        }

        $request = $event->getRequest();
        $write = !$request->isMethodSafe();
        $limit = ($write ? $this->writeLimiter : $this->readLimiter)->create((string) $user->tokenId())->consume();
        if (!$limit->isAccepted()) {
            $event->setResponse(ProblemResponse::fromProblem($request, ApiProblem::rateLimited($limit->getRetryAfter()->getTimestamp() - time())));
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!self::isApi($request)) {
            return;
        }

        $e = $event->getThrowable();
        if ($e instanceof AccessDeniedException || $e instanceof AuthenticationException) {
            // The firewall's own listener answers these: ApiEntryPoint (401) or ApiAccessDeniedHandler (403).
            return;
        }
        $response = match (true) {
            $e instanceof ApiProblem => ProblemResponse::fromProblem($request, $e),
            $e instanceof HttpExceptionInterface => ProblemResponse::create(
                $request,
                $e->getStatusCode(),
                match ($e->getStatusCode()) {
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    default => 'http_error',
                },
                404 === $e->getStatusCode() ? 'No such resource.' : (Response::$statusTexts[$e->getStatusCode()] ?? 'Error'),
                $e->getHeaders(),
            ),
            default => null,
        };

        if (null === $response) {
            // Never leak internals: the log has the exception, the client has the request id.
            $this->logger->error('API request failed.', ['exception' => $e, 'request_id' => ApiRequestId::of($request)]);
            $response = ProblemResponse::create($request, Response::HTTP_INTERNAL_SERVER_ERROR, 'internal_error', 'The request failed.');
        }

        $event->setResponse($response);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApi($request)) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('X-Request-Id', ApiRequestId::of($request));
        $headers->set('Cache-Control', 'no-store');
        $headers->set('X-Content-Type-Options', 'nosniff');
    }

    private static function isApi(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), self::PREFIX);
    }
}
