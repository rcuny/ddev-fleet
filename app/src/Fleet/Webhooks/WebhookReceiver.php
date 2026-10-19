<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookSource;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles one call to `/hooks/<source>/<project>`. The checks run in this order, so a caller without the secret learns
 * nothing beyond "this project has a receiver": unknown receiver 404, body over the cap 413, bad signature 401 (a log
 * warning, never a delivery row), then the body itself (400), the rules, the dedupe and the action.
 * Jira only counts 200 as success and never retries a 4xx.
 */
final readonly class WebhookReceiver
{
    /** The cap on the raw body. Caddy enforces a lower 1 MB first; this is the defence in depth behind it. */
    public const int MAX_BODY_BYTES = 1_048_576;

    private const string DEDUPE_DAYS = '7 days';
    private const string RETENTION_DAYS = '60 days';

    public function __construct(
        private WebhookRepository $webhooks,
        private WebhookSecrets $secrets,
        private RuleMatcher $matcher,
        private WebhookAction $action,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $headers lower-case name => value
     */
    public function receive(WebhookSource $source, string $projectName, string $body, array $headers): ReceiveResult
    {
        $endpoint = $this->webhooks->endpoint($projectName, $source);
        if (null === $endpoint) {
            return ReceiveResult::error(404, 'not found');
        }
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            return ReceiveResult::error(413, 'payload too large');
        }

        $deliveryId = self::deliveryId($source, $headers);
        if (!$this->secrets->accepts($endpoint, $body, $headers['x-hub-signature'] ?? null)) {
            // Project, source and the (cut) delivery id only: no body, no signature, nothing a caller controls in full.
            $this->logger->warning('webhook rejected: bad or missing signature', [
                'source' => $source->value,
                'project' => $projectName,
                'delivery_id' => null === $deliveryId ? null : mb_strcut($deliveryId, 0, WebhookDelivery::FIELD_MAX, 'UTF-8'),
            ]);

            return ReceiveResult::error(401, 'unauthorized');
        }

        $now = $this->clock->now();
        $payload = json_decode($body, true);
        if (!\is_array($payload) || ([] !== $payload && array_is_list($payload))) {
            return $this->failed($source, $projectName, $deliveryId, $now, 'the body is not a JSON object');
        }

        try {
            $outcome = $this->matcher->match($source, $endpoint->project(), $headers, $payload, $endpoint->rules());
        } catch (PayloadInvalid $e) {
            return $this->failed($source, $projectName, $deliveryId, $now, $e->getMessage());
        }

        if (null === $outcome->rule) {
            $reason = $outcome->ignoredReason ?? 'ignored';
            $this->webhooks->saveDelivery(new WebhookDelivery($now, $projectName, $source, $outcome->event, $deliveryId, WebhookDelivery::IGNORED, $reason, $outcome->subject, $outcome->change));

            return new ReceiveResult(200, ['result' => 'ignored', 'reason' => $reason]);
        }

        return $this->act($endpoint, $outcome, $deliveryId, $now);
    }

    private function act(WebhookEndpoint $endpoint, RuleOutcome $outcome, ?string $deliveryId, \DateTimeImmutable $now): ReceiveResult
    {
        $rule = $outcome->rule;
        \assert(null !== $rule);
        $projectName = $endpoint->project()->name();
        $source = $endpoint->source();

        $delivery = new WebhookDelivery($now, $projectName, $source, $outcome->event, $deliveryId, WebhookDelivery::ACCEPTED, null, $outcome->subject, $outcome->change, $rule, $outcome->decision);
        if (null !== $deliveryId) {
            $key = mb_strcut($deliveryId, 0, WebhookDelivery::FIELD_MAX, 'UTF-8');
            if ($this->webhooks->dedupeSeen($projectName, $source, $key)) {
                return $this->duplicate($source, $projectName, $deliveryId, $now, $outcome);
            }
            $delivery->consumeDedupeId();
        }

        try {
            // The id is consumed BEFORE the action runs, so a retry that arrives meanwhile is a duplicate.
            $this->webhooks->saveDelivery($delivery);
        } catch (UniqueConstraintViolationException) {
            // A concurrent twin won the race. The entity manager is closed after this; the twin has the row.
            return new ReceiveResult(200, ['result' => 'duplicate']);
        }

        $result = $this->action->execute(new WebhookActionRequest($projectName, $rule, $outcome->label, $outcome->branch));
        $delivery->recordAction($result->note, $result->jobId);
        $this->webhooks->flush();
        $this->webhooks->prune($now->modify('-'.self::DEDUPE_DAYS), $now->modify('-'.self::RETENTION_DAYS));

        return new ReceiveResult(200, [
            'result' => 'accepted',
            'decision' => $outcome->decision,
            'performed' => $result->performed,
            'note' => $result->note,
        ] + (null === $result->jobId ? [] : ['job' => $result->jobId]));
    }

    private function duplicate(WebhookSource $source, string $projectName, ?string $deliveryId, \DateTimeImmutable $now, RuleOutcome $outcome): ReceiveResult
    {
        $this->webhooks->saveDelivery(new WebhookDelivery($now, $projectName, $source, $outcome->event, $deliveryId, WebhookDelivery::DUPLICATE, 'this delivery id was already handled', $outcome->subject, $outcome->change, $outcome->rule));

        return new ReceiveResult(200, ['result' => 'duplicate']);
    }

    private function failed(WebhookSource $source, string $projectName, ?string $deliveryId, \DateTimeImmutable $now, string $reason): ReceiveResult
    {
        $this->webhooks->saveDelivery(new WebhookDelivery($now, $projectName, $source, 'unknown', $deliveryId, WebhookDelivery::FAILED, $reason));

        return ReceiveResult::error(400, $reason);
    }

    /**
     * Jira repeats `X-Atlassian-Webhook-Identifier` on a retry; Bitbucket repeats `X-Request-UUID`.
     *
     * @param array<string, string> $headers
     */
    private static function deliveryId(WebhookSource $source, array $headers): ?string
    {
        $id = $headers[WebhookSource::Jira === $source ? 'x-atlassian-webhook-identifier' : 'x-request-uuid'] ?? '';

        return '' === $id ? null : $id;
    }
}
