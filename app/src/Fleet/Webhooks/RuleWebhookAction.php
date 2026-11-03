<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Webhook\WebhookRule;

/**
 * Hands a matched rule to the code that performs its action.
 */
final readonly class RuleWebhookAction implements WebhookAction
{
    public function __construct(
        private DeployWebhookAction $deploy,
        private PipelineWebhookAction $pipeline,
    ) {
    }

    public function execute(WebhookActionRequest $request): WebhookActionResult
    {
        return match ($request->rule->action()) {
            WebhookRule::ACTION_DEPLOY => $this->deploy->execute($request),
            WebhookRule::ACTION_RUN_PIPELINE => $this->pipeline->execute($request),
            default => new WebhookActionResult(false, 'the rule has an action this version does not know'),
        };
    }
}
