<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\Job;
use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\DeployInput;
use App\Fleet\Instances\InstanceNaming;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Instances\OperationOptions;
use App\Fleet\Instances\Selector;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;

/**
 * A deploy rule: queues the deploy job through the instance service, exactly as a person's deploy would (same guards,
 * same label allocation), with the trigger `webhook` and the requester `webhook:<source>`.
 *
 * - A rule with a fixed label keeps one instance: when `<project>--<label>` exists, the job is a redeploy of it.
 * - Every other rule allocates a free label (`fle-3`, then `fle-3-1`, ...) and never overwrites.
 *
 * A refusal (project or template gone, the instance busy, the disk guard) is a result with a note, not an exception:
 * the sender has been told "accepted" and the delivery row says why nothing started.
 */
final readonly class DeployWebhookAction
{
    public function __construct(
        private InstanceService $instances,
        private InstanceRepository $repository,
    ) {
    }

    public function execute(WebhookActionRequest $request): WebhookActionResult
    {
        $rule = $request->rule;
        $by = 'webhook:'.$rule->endpoint()->source()->value;
        $template = $rule->params()['template'] ?? null;

        try {
            $existing = null === $request->label ? null : InstanceNaming::compose($request->projectName, $request->label);
            if ($request->replace && null !== $existing && null !== $this->repository->find($existing)) {
                $jobs = $this->instances->request('redeploy', new Selector([$existing]), new OperationOptions(template: $template), JobTrigger::Webhook, $by);
                $what = 'redeploy queued';
            } else {
                $jobs = $this->instances->requestDeploy(new DeployInput($request->projectName, $template, $request->branch, $request->label), JobTrigger::Webhook, $by);
                $what = 'deploy queued';
            }
        } catch (ValidationFailed $e) {
            return new WebhookActionResult(false, 'not deployed: '.implode(' ', $e->errors));
        } catch (NotFound|Refused $e) {
            return new WebhookActionResult(false, 'not deployed: '.$e->getMessage());
        }

        $job = $jobs[0] ?? null;
        if (!$job instanceof Job) {
            return new WebhookActionResult(false, 'not deployed: no job was created');
        }

        return new WebhookActionResult(true, \sprintf('%s: %s (job %d)', $what, $job->instanceId() ?? '?', (int) $job->id()), $job->id(), $job->instanceId());
    }
}
