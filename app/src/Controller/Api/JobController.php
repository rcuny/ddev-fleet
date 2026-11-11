<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Read\Cursor;
use App\Api\Read\JobView;
use App\Api\Read\ProjectVisibility;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Fleet\Jobs\LogReader;
use App\Security\Api\ApiAuthorizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/jobs`, `/jobs/{id}` and `/jobs/{id}/log?offset=N` (scope `read`, limited to the token's projects; a job
 * without a project is for an unrestricted token). The log is the masked, escape-free text the UI shows: the body is
 * the bytes from `offset`, `X-Next-Offset` is where to continue and `X-Job-State` the job's status.
 */
final readonly class JobController
{
    public function __construct(
        private ApiAuthorizer $authorizer,
        private JobRepository $jobs,
        private LogReader $logs,
        private ProjectVisibility $visibility,
    ) {
    }

    #[Route('/api/v1/jobs', name: 'api_v1_jobs', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $token = $this->authorizer->require(ApiScope::Read);
        $cursor = Cursor::fromRequest($request);
        $filters = [];
        $status = $request->query->get('status');
        if (\is_string($status) && '' !== $status) {
            $filters['status'] = JobStatus::tryFrom($status) ?? throw ApiProblem::invalid('invalid_filter', '"status" must be one of: '.implode(', ', array_map(static fn (JobStatus $s): string => $s->value, JobStatus::cases())).'.');
        }
        $trigger = $request->query->get('trigger');
        if (\is_string($trigger) && '' !== $trigger) {
            $filters['trigger'] = JobTrigger::tryFrom($trigger) ?? throw ApiProblem::invalid('invalid_filter', '"trigger" must be one of: '.implode(', ', array_map(static fn (JobTrigger $t): string => $t->value, JobTrigger::cases())).'.');
        }
        foreach (['type', 'instance', 'project'] as $name) {
            $value = $request->query->get($name);
            if (\is_string($value) && '' !== $value) {
                $filters[$name] = $value;
            }
        }
        if (isset($filters['project'])) {
            $this->authorizer->require(ApiScope::Read, $filters['project']);
        } elseif (!$token->unrestricted()) {
            $filters['projects'] = $this->visibility->names($token);
        }
        if ($cursor->position > 0) {
            $filters['before'] = $cursor->position;
        }

        $found = $this->jobs->search($filters, 1, $cursor->limit + 1);
        $rows = $found['rows'];
        $more = \count($rows) > $cursor->limit;
        $rows = \array_slice($rows, 0, $cursor->limit);
        $last = [] === $rows ? null : end($rows)->id();

        return new JsonResponse([
            'jobs' => array_map(JobView::row(...), $rows),
            'next_cursor' => $more && null !== $last ? Cursor::encode($last) : null,
        ]);
    }

    #[Route('/api/v1/jobs/{id}', name: 'api_v1_job', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse(JobView::row($this->find($id)));
    }

    #[Route('/api/v1/jobs/{id}/log', name: 'api_v1_job_log', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function log(int $id, Request $request): Response
    {
        $job = $this->find($id);
        $offset = $request->query->get('offset', '0');
        if (!ctype_digit($offset) || \strlen($offset) > 12) {
            throw ApiProblem::invalid('invalid_offset', '"offset" must be a number of bytes, 0 or more.');
        }
        $chunk = $this->logs->read($job, (int) $offset);

        return new Response($chunk['text'], 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Next-Offset' => (string) $chunk['next'],
            'X-Job-State' => $job->status()->value,
            'X-Log-Done' => $chunk['done'] ? '1' : '0',
        ]);
    }

    /** 404 for a missing job (and a project-less one a limited token may not see), 403 `project_not_allowed` for a job of another project. */
    private function find(int $id): Job
    {
        $token = $this->authorizer->require(ApiScope::Read);
        $job = $this->jobs->find($id) ?? throw ApiProblem::notFound('No such job.');
        $project = $job->projectName();
        if (null === $project) {
            if (!$token->unrestricted()) {
                throw ApiProblem::notFound('No such job.');
            }
        } else {
            $this->authorizer->require(ApiScope::Read, $project);
        }

        return $job;
    }
}
