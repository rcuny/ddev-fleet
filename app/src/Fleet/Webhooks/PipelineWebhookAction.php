<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Fleet\Secrets\SecretOpenFailed;

/**
 * A run-pipeline rule: starts the rule's custom pipeline on its ref, with the project's sealed `pipeline:write` token.
 * Without a token nothing is called and the note says so. Nothing here puts the token in a note, a log or a result.
 */
final readonly class PipelineWebhookAction
{
    public function __construct(
        private WebhookSecrets $secrets,
        private BitbucketPipelineStarter $starter,
    ) {
    }

    public function execute(WebhookActionRequest $request): WebhookActionResult
    {
        $rule = $request->rule;
        $params = $rule->params();
        $repo = $rule->match()['repo'] ?? '';
        $pattern = $params['pattern'] ?? '';
        $ref = $request->branch;
        if ('' === $repo || '' === $pattern || null === $ref) {
            return new WebhookActionResult(false, 'no pipeline started: the rule has no repository, pipeline or ref (the project has no default branch)');
        }

        try {
            $token = $this->secrets->openToken($rule->endpoint());
        } catch (SecretOpenFailed) {
            return new WebhookActionResult(false, 'no pipeline started: the stored pipeline token cannot be opened (secret key lost or replaced); set the token again');
        }
        if (null === $token) {
            return new WebhookActionResult(false, 'no pipeline started: this project has no pipeline token yet (set one on its Webhooks tab or with fleet webhook:token)');
        }

        try {
            $this->starter->start($repo, $pattern, $ref, $token);
        } catch (PipelineStartFailed $e) {
            return new WebhookActionResult(false, 'no pipeline started: '.$e->getMessage());
        }

        return new WebhookActionResult(true, \sprintf('pipeline %s started on %s (%s)', $pattern, $ref, $repo));
    }
}
