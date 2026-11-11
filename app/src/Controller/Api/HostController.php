<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Read\CapacityVerdict;
use App\Api\Read\ProjectVisibility;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Host\Capacity\HostCapacity;
use App\Fleet\Upgrade\InstalledVersion;
use App\Security\Api\ApiAuthorizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/host` and `GET /api/v1/host/capacity` (scope `read`). The capacity document is the schema-1 report of
 * `fleet:host:capacity --json`; a token limited to some projects gets 403 unless it passes
 * `?project=<one of its projects>`: then it gets the scoped document (verdict, own figures, remaining instance headroom).
 * `?project=&template=` adds the dispatch verdict (CapacityVerdict) for an unrestricted token too.
 */
final readonly class HostController
{
    public function __construct(
        private ApiAuthorizer $authorizer,
        private HostCapacity $capacity,
        private HostSettingsRepository $settings,
        private InstalledVersion $installed,
        private InstanceRepository $instances,
        private ProjectRepository $projects,
        private ProjectVisibility $visibility,
    ) {
    }

    #[Route('/api/v1/host', name: 'api_v1_host', methods: ['GET'])]
    public function host(): JsonResponse
    {
        $token = $this->authorizer->require(ApiScope::Read);
        if (!$token->unrestricted()) {
            throw ApiProblem::hostWide();
        }
        $version = $this->installed->version();

        return new JsonResponse([
            'host' => $this->settings->get()?->domain() ?? '',
            'version' => $this->installed->raw(),
            'generation' => $version?->major,
            'instances' => [
                'total' => \count($this->instances->ids()),
                'by_state' => $this->instances->countByState(),
            ],
            'projects' => \count($this->visibility->projects($token)),
        ]);
    }

    #[Route('/api/v1/host/capacity', name: 'api_v1_host_capacity', methods: ['GET'])]
    public function capacity(Request $request): JsonResponse
    {
        $token = $this->authorizer->require(ApiScope::Read);
        $project = $request->query->get('project');
        $template = $request->query->get('template');
        if (!$token->unrestricted() && (null === $project || '' === $project)) {
            throw ApiProblem::hostWide();
        }
        if (null !== $project && '' === $project) {
            throw ApiProblem::invalid('invalid_filter', '"project" must be a project name.');
        }
        if (null !== $template && ('' === $template || null === $project)) {
            throw ApiProblem::invalid('invalid_filter', '"template" must be a template name and needs "project".');
        }
        if (null !== $project) {
            $this->authorizer->require(ApiScope::Read, $project);
        }

        $report = $this->capacity->report();
        if (null !== $project) {
            $found = $this->projects->findByName($project) ?? throw ApiProblem::notFound(\sprintf('No project "%s".', $project));
            $name = $template ?? $found->defaultTemplate()
                ?? throw ApiProblem::invalid('invalid_filter', 'The project has no default template: pass "template".');
            $report['verdict'] = CapacityVerdict::evaluate($report, $project, $name);
            if (!$token->unrestricted()) {
                return new JsonResponse($this->scoped($report['verdict'], $project, \count($this->instances->byProject($found))));
            }
        }

        return new JsonResponse($report);
    }

    /**
     * What a project-limited token gets: the verdict and the figures of its own project, and the instance-cap
     * headroom as a remaining count only (no other project, no host totals).
     *
     * @param array<string, mixed>|null $verdict
     *
     * @return array<string, mixed>
     */
    private function scoped(?array $verdict, string $project, int $instanceCount): array
    {
        $verdict ??= ['project' => $project, 'template' => null, 'ok' => false, 'reasons' => [], 'slots' => [], 'cores' => []];
        $instances = \is_array($verdict['instances'] ?? null) ? $verdict['instances'] : [];
        unset($verdict['instances']);

        return [
            'schema' => 1,
            'scope' => 'project',
            'project' => $project,
            'verdict' => $verdict,
            'instances' => [
                'project_count' => $instanceCount,
                'remaining' => max(0, (int) ($instances['max'] ?? 0) - (int) ($instances['count'] ?? 0)),
            ],
        ];
    }
}
