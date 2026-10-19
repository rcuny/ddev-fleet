<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Webhook\WebhookRule;

/**
 * What RuleMatcher made of one event: the facts for the log, and the matching rule (or why none matched).
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
    ) {
    }

    public static function ignored(string $event, ?string $subject, ?string $change, string $reason): self
    {
        return new self($event, $subject, $change, null, $reason);
    }
}
