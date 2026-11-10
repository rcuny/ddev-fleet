<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Write\WriteSupport;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\InstanceService;
use App\Security\Api\ApiAuthorizer;
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
        private InstanceService $service,
        private WriteSupport $support,
    ) {
    }

    #[Route('/api/v1/jobs/{id}/retry', name: 'api_v1_job_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(int $id): Response
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
        }

        $new = $this->support->run(fn () => $this->service->retry($id, JobTrigger::Api, $token->getUserIdentifier()));
        $this->support->audit($token, 'retry', 'job='.$id, $new);

        return $this->support->accepted($new);
    }
}
