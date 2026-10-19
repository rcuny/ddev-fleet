<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * What a matched rule does. The receiver decides and records; an action executes. Until the instance jobs exist (M0.04)
 * the only implementation is UnavailableWebhookAction, which performs nothing.
 */
interface WebhookAction
{
    public function execute(WebhookActionRequest $request): WebhookActionResult;
}
