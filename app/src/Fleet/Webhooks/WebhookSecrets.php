<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Project\Project;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Secrets\SealedSecret;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretOpenFailed;
use App\Fleet\Secrets\SecretStore;
use Psr\Clock\ClockInterface;

/**
 * The webhook HMAC secrets: created and rotated here, opened only to check a signature. A secret is returned once, by
 * issue(), and never again: nothing else reads it back (not the pages, not the CLI, not a log).
 */
final readonly class WebhookSecrets
{
    private const string SCOPE = 'webhook';

    public function __construct(
        private WebhookRepository $webhooks,
        private SecretBox $box,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Creates the endpoint with a new secret, or replaces the secret of an existing one (the old one stops working at
     * once). Returns the new secret in clear: show it to the person now, it cannot be shown again.
     */
    public function issue(Project $project, WebhookSource $source): string
    {
        $secret = bin2hex(random_bytes(32));
        $sealed = $this->box->seal($secret, self::context($project, $source));
        $now = $this->clock->now();

        $endpoint = $this->webhooks->endpoint($project->name(), $source);
        if (null === $endpoint) {
            $this->webhooks->saveEndpoint(new WebhookEndpoint($project, $source, $sealed->ciphertext, $sealed->nonce, $sealed->keyVersion, $now));
        } else {
            $endpoint->replaceSecret($sealed->ciphertext, $sealed->nonce, $sealed->keyVersion, $now);
            $this->webhooks->saveEndpoint($endpoint);
        }

        return $secret;
    }

    /**
     * Whether the body was signed with the endpoint's secret. A secret that cannot be opened (a lost key) never
     * validates anything.
     */
    public function accepts(WebhookEndpoint $endpoint, string $body, ?string $header): bool
    {
        try {
            $secret = $this->box->open(
                new SealedSecret($endpoint->ciphertext(), $endpoint->nonce(), $endpoint->keyVersion()),
                self::context($endpoint->project(), $endpoint->source()),
            );
        } catch (SecretOpenFailed) {
            return false;
        }

        return Signature::valid($body, $header, $secret);
    }

    private static function context(Project $project, WebhookSource $source): string
    {
        return SecretStore::context(self::SCOPE, $project->name(), $source->value);
    }
}
