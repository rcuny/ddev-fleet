<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Webhook\WebhookRule;

/**
 * A decision to act: the rule that matched and the values resolved from the event. `label` is the instance label for
 * a deploy; `branch` the branch to deploy or the ref to build.
 */
final readonly class WebhookActionRequest
{
    public function __construct(
        public string $projectName,
        public WebhookRule $rule,
        public ?string $label,
        public ?string $branch,
    ) {
    }
}
