<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Webhook\WebhookSource;
use App\Fleet\Webhooks\WebhookReceiver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public endpoint Jira and Bitbucket call. Caddy lets `/hooks/*` through without the login gate and caps the body;
 * the request is authenticated here by its HMAC signature, inside WebhookReceiver. No session, no cookies, no CSRF.
 */
final readonly class WebhookReceiverController
{
    public function __construct(private WebhookReceiver $receiver)
    {
    }

    #[Route('/hooks/{source}/{project}', name: 'webhook_receive', requirements: ['source' => 'jira|bitbucket', 'project' => '[a-z0-9-]{1,40}'], methods: ['POST'])]
    public function receive(Request $request, string $source, string $project): Response
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        $result = $this->receiver->receive(WebhookSource::from($source), $project, $request->getContent(), $headers);
        $response = new JsonResponse($result->body, $result->status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
