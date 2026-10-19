<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookRule;
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

    /** A signed body seen again inside this window is a replay, whatever delivery id it carries. */
    private const string REPLAY_WINDOW = '1 hour';
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
     * @param string|\Closure(): string $body    the raw body, or a function that reads it: the declared `Content-Length` is
     *                                           checked before the body is read, so an oversized request is never loaded
     * @param array<string, string>     $headers lower-case name => value
     */
    public function receive(WebhookSource $source, string $projectName, string|\Closure $body, array $headers): ReceiveResult
    {
        $endpoint = $this->webhooks->endpoint($projectName, $source);
        if (null === $endpoint) {
            return ReceiveResult::error(404, 'not found');
        }
        $declared = $headers['content-length'] ?? '';
        if ('' !== $declared && ctype_digit($declared) && (int) $declared > self::MAX_BODY_BYTES) {
            return ReceiveResult::error(413, 'payload too large');
        }
        $body = $body instanceof \Closure ? $body() : $body;
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            return ReceiveResult::error(413, 'payload too large');
        }

        $deliveryId = self::deliveryId($source, $headers);
        $verdict = $this->secrets->verify($endpoint, $body, $headers['x-hub-signature'] ?? null);
        if (SignatureVerdict::Valid !== $verdict) {
            // Project, source and the (cut) delivery id only: no body, no signature, nothing a caller controls in full.
            $context = [
                'source' => $source->value,
                'project' => $projectName,
                'delivery_id' => null === $deliveryId ? null : mb_strcut($deliveryId, 0, WebhookDelivery::FIELD_MAX, 'UTF-8'),
            ];
            if (SignatureVerdict::Unreadable === $verdict) {
                // Not the sender's fault: the stored secret cannot be opened (the secret key was lost or replaced).
                // Rotate the secret to recover; until then every delivery of this receiver is refused.
                $this->logger->error('webhook rejected: the stored secret cannot be opened (secret key lost or replaced); rotate the webhook secret', $context);
            } else {
                $this->logger->warning('webhook rejected: bad or missing signature', $context);
            }

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
        } catch (TargetUnresolvable $e) {
            // Nothing is claimed, so the sender's retry can succeed once the rule or the branch is fixed.
            $this->webhooks->saveDelivery(new WebhookDelivery($now, $projectName, $source, $headers['x-event-key'] ?? 'unknown', $deliveryId, WebhookDelivery::FAILED, $e->getMessage()));

            return ReceiveResult::error(422, $e->getMessage());
        }

        if (null === $outcome->rule) {
            $reason = $outcome->ignoredReason ?? 'ignored';
            $this->webhooks->saveDelivery(new WebhookDelivery($now, $projectName, $source, $outcome->event, $deliveryId, WebhookDelivery::IGNORED, $reason, $outcome->subject, $outcome->change));

            return new ReceiveResult(200, ['result' => 'ignored', 'reason' => $reason]);
        }

        return $this->act($endpoint, $outcome, $deliveryId, $now, hash('sha256', $body));
    }

    private function act(WebhookEndpoint $endpoint, RuleOutcome $outcome, ?string $deliveryId, \DateTimeImmutable $now, string $bodyHash): ReceiveResult
    {
        $rule = $outcome->rule;
        \assert(null !== $rule);
        $projectName = $endpoint->project()->name();
        $source = $endpoint->source();

        $delivery = new WebhookDelivery($now, $projectName, $source, $outcome->event, $deliveryId, WebhookDelivery::ACCEPTED, null, $outcome->subject, $outcome->change, $rule, $outcome->decision);
        $delivery->withBodyHash($bodyHash);
        if (null !== $deliveryId) {
            $key = mb_strcut($deliveryId, 0, WebhookDelivery::FIELD_MAX, 'UTF-8');
            if ($this->webhooks->dedupeSeen($projectName, $source, $key)) {
                return $this->duplicate($source, $projectName, $deliveryId, $now, $outcome, 'this delivery id was already handled');
            }
            $delivery->consumeDedupeId();
        }
        if ($this->webhooks->bodySeen($projectName, $source, $bodyHash, $now->modify('-'.self::REPLAY_WINDOW))) {
            return $this->duplicate($source, $projectName, $deliveryId, $now, $outcome, 'this body was already handled');
        }

        try {
            // The id is consumed BEFORE the action runs, so a retry that arrives meanwhile is a duplicate.
            $this->webhooks->saveDelivery($delivery);
        } catch (UniqueConstraintViolationException) {
            // A concurrent twin won the race. The entity manager is closed after this; the twin has the row.
            return new ReceiveResult(200, ['result' => 'duplicate']);
        }

        foreach ($outcome->skipped as $skipped) {
            $this->webhooks->saveDelivery(new WebhookDelivery($now, $projectName, $source, $outcome->event, $deliveryId, WebhookDelivery::IGNORED, $skipped['reason'], $skipped['subject'], $outcome->change, $rule));
        }

        $results = [];
        foreach (self::requests($projectName, $outcome, $rule) as $request) {
            $results[] = $this->execute($request, $source);
        }
        $performed = array_filter($results, static fn (WebhookActionResult $r): bool => $r->performed);
        $note = implode('; ', array_map(static fn (WebhookActionResult $r): string => $r->note, $results));
        $jobId = null;
        $instance = null;
        foreach ($results as $result) {
            $jobId ??= $result->jobId;
            $instance ??= $result->instance;
        }

        try {
            $this->webhooks->recordOutcome($delivery, $note, $jobId, [] === $performed);
            $this->webhooks->prune($now->modify('-'.self::DEDUPE_DAYS), $now->modify('-'.self::RETENTION_DAYS));
        } catch (\Throwable $e) {
            // The sender already has its answer to give; a log row that could not be updated must not turn it into a 500.
            $this->logger->error('webhook outcome could not be recorded', ['source' => $source->value, 'project' => $projectName, 'error' => $e::class]);
        }

        $body = [
            'result' => [] === $performed ? 'failed' : 'accepted',
            'decision' => $outcome->decision,
            'performed' => [] !== $performed,
            'note' => $note,
        ] + (null === $jobId ? [] : ['job' => $jobId]) + (null === $instance ? [] : ['instance' => $instance]);
        if (\count($results) > 1) {
            $body['actions'] = array_map(static fn (WebhookActionResult $r): array => ['performed' => $r->performed, 'note' => $r->note, 'job' => $r->jobId, 'instance' => $r->instance], $results);
        }

        return new ReceiveResult(200, $body);
    }

    /**
     * @return list<WebhookActionRequest>
     */
    private static function requests(string $projectName, RuleOutcome $outcome, WebhookRule $rule): array
    {
        if ([] === $outcome->targets) {
            return [new WebhookActionRequest($projectName, $rule, $outcome->label, $outcome->branch)];
        }

        return array_map(static fn (DeployTarget $t): WebhookActionRequest => new WebhookActionRequest($projectName, $t->rule, $t->label, $t->branch, $t->replace), $outcome->targets);
    }

    /**
     * One action, never throwing: whatever goes wrong is a failed result with a note, and the log says which kind.
     */
    private function execute(WebhookActionRequest $request, WebhookSource $source): WebhookActionResult
    {
        try {
            return $this->action->execute($request);
        } catch (\Throwable $e) {
            // The class only: a message can carry a URL or a path, never a token, but nothing here needs more.
            $this->logger->error('webhook action failed unexpectedly', ['source' => $source->value, 'project' => $request->projectName, 'action' => $request->rule->action(), 'error' => $e::class]);

            return new WebhookActionResult(false, 'the action failed unexpectedly; see the application log');
        }
    }

    private function duplicate(WebhookSource $source, string $projectName, ?string $deliveryId, \DateTimeImmutable $now, RuleOutcome $outcome, string $reason): ReceiveResult
    {
        $this->webhooks->saveDelivery(new WebhookDelivery($now, $projectName, $source, $outcome->event, $deliveryId, WebhookDelivery::DUPLICATE, $reason, $outcome->subject, $outcome->change, $outcome->rule));

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
