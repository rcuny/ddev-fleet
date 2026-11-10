<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Read\CapacityVerdict;
use App\Api\Write\DestroyGuard;
use App\Api\Write\JsonBody;
use App\Api\Write\WriteSupport;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Host\Capacity\HostCapacity;
use App\Fleet\Instances\DeployInput;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Instances\OperationOptions;
use App\Fleet\Instances\Selector;
use App\Security\Api\ApiAuthorizer;
use App\Security\Api\ApiTokenUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The instance write endpoints (design section 6.1): deploy and redeploy need `deploy`; start and stop need
 * `lifecycle`; destroy needs `lifecycle` and the `lifecycle:destroy` flag. Every one is limited to the token's
 * projects, answers `202` with the job to poll, and is audited. Destroy refuses the protected `*--main` instances and
 * requires `If-Match` (428 when missing) on the instance's `created_at`, so a stale id never destroys a newer instance that reused it.
 * There is no dispatch endpoint: dispatch stays the Jira webhook.
 */
final readonly class InstanceWriteController
{
    private const int BATCH_MAX = 20;

    public function __construct(
        private ApiAuthorizer $authorizer,
        private InstanceService $service,
        private InstanceRepository $instances,
        private ProjectRepository $projects,
        private HostCapacity $capacity,
        private WriteSupport $support,
    ) {
    }

    #[Route('/api/v1/instances', name: 'api_v1_instance_deploy', methods: ['POST'])]
    public function deploy(Request $request): Response
    {
        $this->authorizer->require(ApiScope::Deploy);
        $body = JsonBody::of($request);
        $project = $body->string('project', true) ?? '';
        $token = $this->authorizer->require(ApiScope::Deploy, $project);
        $template = $body->string('template');
        $branch = $body->string('branch');
        $label = $body->string('label');

        $found = $this->projects->findByName($project) ?? throw ApiProblem::notFound(\sprintf('No project "%s".', $project));
        $gate = CapacityVerdict::evaluate($this->capacity->report(), $project, $template ?? $found->defaultTemplate() ?? '');
        // No CPU allotment known (a host that reports no threads) means nothing to gate on.
        if (null !== $gate && !$gate['ok'] && $gate['cores']['effective_threads'] > 0) {
            throw ApiProblem::capacityFull($gate);
        }

        $jobs = $this->support->run(fn (): array => $this->service->requestDeploy(new DeployInput($project, $template, $branch, $label), JobTrigger::Api, $token->getUserIdentifier()));
        $job = $jobs[0];
        $this->support->audit($token, 'deploy', (string) $job->instanceId(), $job);

        return $this->support->accepted($job);
    }

    #[Route('/api/v1/instances/{id}/redeploy', name: 'api_v1_instance_redeploy', methods: ['POST'], requirements: ['id' => '[^/:]+'])]
    public function redeploy(string $id, Request $request): Response
    {
        $token = $this->authorizeInstance(ApiScope::Deploy, $id);
        $body = JsonBody::of($request);
        $options = new OperationOptions(template: $body->string('template'), force: $body->bool('force'), branch: $body->string('branch'));

        return $this->operate('redeploy', $token, $id, $options);
    }

    #[Route('/api/v1/instances/{id}/start', name: 'api_v1_instance_start', methods: ['POST'], requirements: ['id' => '[^/:]+'])]
    public function start(string $id): Response
    {
        return $this->operate('start', $this->authorizeInstance(ApiScope::Lifecycle, $id), $id, new OperationOptions());
    }

    #[Route('/api/v1/instances/{id}/stop', name: 'api_v1_instance_stop', methods: ['POST'], requirements: ['id' => '[^/:]+'])]
    public function stop(string $id): Response
    {
        return $this->operate('stop', $this->authorizeInstance(ApiScope::Lifecycle, $id), $id, new OperationOptions());
    }

    #[Route('/api/v1/instances/{id}', name: 'api_v1_instance_destroy', methods: ['DELETE'], requirements: ['id' => '[^/:]+'])]
    public function destroy(string $id, Request $request): Response
    {
        $token = $this->authorizeInstance(ApiScope::LifecycleDestroy, $id, ApiScope::Lifecycle);
        $instance = $this->instances->find($id) ?? throw ApiProblem::notFound('No such instance.');
        DestroyGuard::check($instance, $request->headers->get('If-Match'));

        return $this->operate('destroy', $token, $id, new OperationOptions());
    }

    /**
     * `POST /api/v1/instances:destroy-batch` with `{"ids": [...], "if_match": {"<id>": "<created_at>"}}`: one job per
     * instance and a result per id (`207`). A refused id does not stop the others.
     */
    #[Route('/api/v1/instances:destroy-batch', name: 'api_v1_instance_destroy_batch', methods: ['POST'])]
    public function destroyBatch(Request $request): Response
    {
        $token = $this->authorizer->require(ApiScope::Lifecycle);
        $this->authorizer->require(ApiScope::LifecycleDestroy);
        $body = JsonBody::of($request);
        $ids = $body->array('ids');
        if (null === $ids || [] === $ids || !array_is_list($ids) || [] !== array_filter($ids, static fn (mixed $v): bool => !\is_string($v) || '' === $v)) {
            throw ApiProblem::validation(['ids' => 'Must be a non-empty list of instance ids.']);
        }
        if (\count($ids) > self::BATCH_MAX) {
            throw ApiProblem::validation(['ids' => \sprintf('At most %d ids per call.', self::BATCH_MAX)]);
        }
        /** @var list<string> $ids */
        $ids = array_values(array_unique($ids));
        $match = $body->array('if_match') ?? [];

        $results = [];
        foreach ($ids as $id) {
            $ifMatch = $match[$id] ?? null;
            try {
                $instance = $this->instances->find($id);
                if (null === $instance) {
                    throw ApiProblem::notFound('No such instance.');
                }
                $this->authorizer->require(ApiScope::Lifecycle, $instance->project()->name());
                $this->authorizer->require(ApiScope::LifecycleDestroy, $instance->project()->name());
                DestroyGuard::check($instance, \is_string($ifMatch) ? $ifMatch : null);
                $job = $this->support->run(fn (): array => $this->service->request('destroy', new Selector([$id]), new OperationOptions(), JobTrigger::Api, $token->getUserIdentifier()))[0];
                $this->support->audit($token, 'destroy', $id, $job);
                $results[] = ['id' => $id, 'accepted' => true, 'job' => WriteSupport::jobRef($job)];
            } catch (ApiProblem $problem) {
                $results[] = ['id' => $id, 'accepted' => false, 'status' => $problem->getStatusCode(), 'code' => $problem->problemCode(), 'detail' => $problem->getMessage()];
            }
        }

        return new JsonResponse(['results' => $results], Response::HTTP_MULTI_STATUS);
    }

    /**
     * Scope(s) and project check for an existing instance; 404 for a missing one (after the scope check).
     * $also is a second scope required together with $scope.
     */
    private function authorizeInstance(ApiScope $scope, string $id, ?ApiScope $also = null): ApiTokenUser
    {
        $instance = $this->instances->find($id);
        if (null === $instance) {
            $this->authorizer->require($also ?? $scope);
            $this->authorizer->require($scope);

            throw ApiProblem::notFound('No such instance.');
        }
        $project = $instance->project()->name();
        if (null !== $also) {
            $this->authorizer->require($also, $project);
        }

        return $this->authorizer->require($scope, $project);
    }

    private function operate(string $op, ApiTokenUser $token, string $id, OperationOptions $options): Response
    {
        $job = $this->support->run(fn (): array => $this->service->request($op, new Selector([$id]), $options, JobTrigger::Api, $token->getUserIdentifier()))[0];
        $this->support->audit($token, $op, $id, $job);

        return $this->support->accepted($job);
    }
}
