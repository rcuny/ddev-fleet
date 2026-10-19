<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * Records that nothing was done. M0.04 replaces this alias with the implementation that submits the deploy job.
 */
final readonly class UnavailableWebhookAction implements WebhookAction
{
    public const string NOTE = 'deploy action not available until M0.04';

    public function execute(WebhookActionRequest $request): WebhookActionResult
    {
        return new WebhookActionResult(false, self::NOTE);
    }
}
