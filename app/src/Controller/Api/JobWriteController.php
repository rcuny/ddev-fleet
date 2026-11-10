<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Write\DestroyGuard;
use App\Api\Write\WriteSupport;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\InstanceService;
use App\Security\Api\ApiAuthorizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /api/v1/jobs/{id}/retry`: repeats a failed or interrupted job of one of the token's projects. The scope is
 * the one the repeated work needs: `deploy` for a deploy or redeploy, `lifecycle` for start and stop, and
 * `lifecycle` plus `lifecycle:destroy` for a destroy. A job without a project cannot be retried over the API.
 */
final readonly class JobWriteController
{
    public function __construct(
        private ApiAuthorizer $authorizer,
        private JobRepository $jobs,
        private InstanceRepository $instances,
        private InstanceService $service,
        private WriteSupport $support,
    ) {
    }

    #[Route('/api/v1/jobs/{id}/retry', name: 'api_v1_job_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(int $id, Request $request): Response
    {
        $caller = $this->authorizer->token();
        if (!$caller->hasScope(ApiScope::Deploy) && !$caller->hasScope(ApiScope::Lifecycle)) {
            $this->authorizer->require(ApiScope::Lifecycle);
        }
        $job = $this->jobs->find($id) ?? throw ApiProblem::notFound('No such job.');
        $project = $job->projectName() ?? throw ApiProblem::notFound('No such job.');
        $scope = \in_array($job->type(), ['deploy', 'redeploy'], true) ? ApiScope::Deploy : ApiScope::Lifecycle;
        $token = $this->authorizer->require($scope, $project);
        if ('destroy' === $job->type()) {
            $this->authorizer->require(ApiScope::LifecycleDestroy, $project);
            // A retried destroy goes through the same protections as a new one, including If-Match.
            $instance = null === $job->instanceId() ? null : $this->instances->find($job->instanceId());
            if (null === $instance) {
                throw ApiProblem::notFound('No such instance.');
            }
            DestroyGuard::check($instance, $request->headers->get('If-Match'));
        }

        $new = $this->support->run(fn () => $this->service->retry($id, JobTrigger::Api, $token->getUserIdentifier()));
        $this->support->audit($token, 'retry', 'job='.$id, $new);

        return $this->support->accepted($new);
    }
}
