<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Webhook\WebhookRule;

/**
 * One instance a matched rule would deploy: the rule, the branch and the label (already fitted to the 63 characters of
 * an instance id). `replace` is a rule with a fixed label: it keeps ONE instance, rebuilt on every push; every other
 * deploy allocates a free label (`-1`, `-2`, ...) and never overwrites.
 */
final readonly class DeployTarget
{
    public function __construct(
        public WebhookRule $rule,
        public string $branch,
        public string $label,
        public bool $replace = false,
    ) {
    }
}
