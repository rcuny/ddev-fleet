<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Webhook\WebhookRule;

/**
 * What RuleMatcher made of one event: the facts for the log, and the matching rule (or why none matched). A deploy
 * rule also lists its targets (a Bitbucket push can name several branches); `skipped` are the branches that matched a
 * rule but were left out, each with its reason, and get a delivery row of their own.
 */
final readonly class RuleOutcome
{
    public function __construct(
        public string $event,
        public ?string $subject,
        public ?string $change,
        public ?WebhookRule $rule,
        public ?string $ignoredReason,
        public ?string $label = null,
        public ?string $branch = null,
        public ?string $decision = null,
        /** @var list<DeployTarget> */
        public array $targets = [],
        /** @var list<array{subject: string, reason: string}> */
        public array $skipped = [],
    ) {
    }

    public static function ignored(string $event, ?string $subject, ?string $change, string $reason): self
    {
        return new self($event, $subject, $change, null, $reason);
    }
}
