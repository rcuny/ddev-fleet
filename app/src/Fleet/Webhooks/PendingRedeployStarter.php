<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\JobTrigger;
use App\Domain\Job\PendingRedeploy;
use App\Domain\Job\PendingRedeployRepository;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Instances\OperationOptions;
use App\Fleet\Instances\Selector;
use App\Fleet\Jobs\JobFinished;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * FLE-220: when a job ends, starts the redeploy a webhook asked for meanwhile (one, on the newest request). The
 * request goes through InstanceService like any redeploy, so the same guards apply. The row stays when the instance
 * got busy again in between (the next job's end picks it up) and goes when the instance or template is gone.
 */
final readonly class PendingRedeployStarter
{
    public function __construct(
        private PendingRedeployRepository $pending,
        private InstanceRepository $repository,
        private InstanceService $instances,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onJobFinished(JobFinished $event): void
    {
        $instanceId = $event->job->instanceId();
        if (null === $instanceId) {
            return;
        }
        $pending = $this->pending->find($instanceId);
        if (!$pending instanceof PendingRedeploy) {
            return;
        }

        // Taken first: with a transport that runs the job at once (sync://) the new job ends inside request(), and its
        // own end must not find this row again.
        $template = $pending->template();
        $requestedBy = $pending->requestedBy();
        $requestedAt = $pending->requestedAt();
        $requested = $pending->branch();
        $this->pending->remove($pending);

        // As the webhook action does: the branch is passed only when it differs from the instance's.
        $branch = null !== $requested && $requested !== $this->repository->find($instanceId)?->branch() ? $requested : null;

        try {
            $this->instances->request('redeploy', new Selector([$instanceId]), new OperationOptions(template: $template, branch: $branch), JobTrigger::Webhook, $requestedBy);
        } catch (Refused $e) {
            if (Refused::BUSY === $e->reason) {
                // Another job took the instance in between: wait for that one.
                $this->pending->remember($instanceId, $requested, $template, $requestedBy, $requestedAt);

                return;
            }
            $this->logger->warning('Pending redeploy of {instance} dropped: {reason}', ['instance' => $instanceId, 'reason' => $e->getMessage()]);
        } catch (NotFound|ValidationFailed $e) {
            $this->logger->warning('Pending redeploy of {instance} dropped: {reason}', ['instance' => $instanceId, 'reason' => $e->getMessage()]);
        }
    }
}
