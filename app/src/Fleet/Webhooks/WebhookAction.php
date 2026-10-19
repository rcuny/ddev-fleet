<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * What a matched rule does. The receiver decides and records; an action executes. The implementation is
 * RuleWebhookAction, which hands a deploy rule to DeployWebhookAction and a run-pipeline rule to PipelineWebhookAction.
 * An action returns a result for every refusal it can name; the receiver turns anything it throws into a failed one.
 */
interface WebhookAction
{
    public function execute(WebhookActionRequest $request): WebhookActionResult;
}
