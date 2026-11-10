<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Read\Cursor;
use App\Api\Read\JobView;
use App\Api\Read\ProjectVisibility;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobRepository;
use App\Fleet\Instances\InstanceHostnames;
use App\Security\Api\ApiAuthorizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/instances` and `/instances/{id}` (scope `read`, limited to the token's projects). Filters on the list:
 * `project`, `branch`, `state`, `label`. `busy` is the queued or running job on the instance, if any.
 */
final readonly class InstanceController
{
    private const int LAST_JOBS = 5;

    public function __construct(
        private ApiAuthorizer $authorizer,
        private InstanceRepository $instances,
        private JobRepository $jobs,
        private InstanceHostnames $hostnames,
        private ProjectVisibility $visibility,
    ) {
    }

    #[Route('/api/v1/instances', name: 'api_v1_instances', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $token = $this->authorizer->require(ApiScope::Read);
        $project = self::filter($request, 'project');
        if (null !== $project) {
            $this->authorizer->require(ApiScope::Read, $project);
        }
        $state = self::filter($request, 'state');
        if (null !== $state && null === InstanceState::tryFrom($state)) {
            throw ApiProblem::invalid('invalid_filter', '"state" must be one of: '.implode(', ', array_map(static fn (InstanceState $s): string => $s->value, InstanceState::cases())).'.');
        }
        $branch = self::filter($request, 'branch');
        $label = self::filter($request, 'label');
        $visible = $this->visibility->names($token);

        $rows = array_values(array_filter(
            $this->instances->all(),
            static fn (Instance $i): bool => \in_array($i->project()->name(), $visible, true)
                && (null === $project || $i->project()->name() === $project)
                && (null === $state || $i->state()->value === $state)
                && (null === $branch || $i->branch() === $branch)
                && (null === $label || $i->label() === $label),
        ));
        usort($rows, static fn (Instance $a, Instance $b): int => $a->id() <=> $b->id());
        $page = Cursor::fromRequest($request)->slice($rows);
        $busy = $this->jobs->activeJobIds(array_map(static fn (Instance $i): string => $i->id(), $page['items']));

        return new JsonResponse([
            'instances' => array_map(fn (Instance $i): array => $this->row($i, $busy[$i->id()] ?? null), $page['items']),
            'next_cursor' => $page['next_cursor'],
        ]);
    }

    #[Route('/api/v1/instances/{id}', name: 'api_v1_instance', methods: ['GET'], requirements: ['id' => '[^/:]+'])]
    public function show(string $id): JsonResponse
    {
        $instance = $this->instances->find($id);
        if (null === $instance) {
            $this->authorizer->require(ApiScope::Read);

            throw ApiProblem::notFound('No such instance.');
        }
        $this->authorizer->require(ApiScope::Read, $instance->project()->name());

        $row = $this->row($instance, $this->jobs->activeJobIds([$id])[$id] ?? null);
        $row['jobs'] = array_map(JobView::row(...), $this->jobs->forInstance($id, self::LAST_JOBS));

        return new JsonResponse($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Instance $i, ?int $busyJob): array
    {
        return [
            'id' => $i->id(),
            'project' => $i->project()->name(),
            'label' => $i->label(),
            'state' => $i->state()->value,
            'branch' => $i->branch(),
            'template' => $i->templateName(),
            'head' => $i->headSha(),
            'ram_mib' => $i->ramMib(),
            'requested_by' => $i->requestedBy(),
            'url' => 'https://'.$this->hostnames->primary($i->id()),
            'created_at' => $i->createdAt()->format(\DATE_ATOM),
            'deployed_at' => $i->deployedAt()?->format(\DATE_ATOM),
            'last_observed_at' => $i->lastObservedAt()?->format(\DATE_ATOM),
            'busy' => null === $busyJob ? null : ['job_id' => $busyJob],
        ];
    }

    private static function filter(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);
        if (null === $value || '' === $value) {
            return null;
        }

        return $value;
    }
}
