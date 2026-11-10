<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Read\Cursor;
use App\Api\Read\ProjectVisibility;
use App\Command\Support\Shapes;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Projects\ProjectSnapshot;
use App\Security\Api\ApiAuthorizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/projects[/{p}]`, `/projects/{p}/templates` and `/templates/{p}/{t}` (scope `read`, limited to the
 * token's projects). The same shapes as `fleet:project:show --json` and `fleet:template:show --json`, so no secret
 * value: environment values are the placeholders the template holds.
 */
final readonly class ProjectController
{
    public function __construct(
        private ApiAuthorizer $authorizer,
        private ProjectRepository $projects,
        private TemplateRepository $templates,
        private ProjectVisibility $visibility,
    ) {
    }

    #[Route('/api/v1/projects', name: 'api_v1_projects', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $token = $this->authorizer->require(ApiScope::Read);
        $page = Cursor::fromRequest($request)->slice($this->visibility->projects($token));

        return new JsonResponse([
            'projects' => array_map(static fn (Project $p): array => ProjectSnapshot::fromProject($p)->toArray(), $page['items']),
            'next_cursor' => $page['next_cursor'],
        ]);
    }

    #[Route('/api/v1/projects/{project}', name: 'api_v1_project', methods: ['GET'], requirements: ['project' => '[^/]+'])]
    public function show(string $project): JsonResponse
    {
        return new JsonResponse(ProjectSnapshot::fromProject($this->find($project))->toArray());
    }

    #[Route('/api/v1/projects/{project}/templates', name: 'api_v1_project_templates', methods: ['GET'], requirements: ['project' => '[^/]+'])]
    public function templates(string $project, Request $request): JsonResponse
    {
        $found = $this->find($project);
        $page = Cursor::fromRequest($request)->slice($this->templates->forProject($found));

        return new JsonResponse([
            'templates' => array_map(Shapes::template(...), $page['items']),
            'next_cursor' => $page['next_cursor'],
        ]);
    }

    #[Route('/api/v1/templates/{project}/{template}', name: 'api_v1_template', methods: ['GET'], requirements: ['project' => '[^/]+', 'template' => '[^/]+'])]
    public function template(string $project, string $template): JsonResponse
    {
        $found = $this->find($project);

        return new JsonResponse(Shapes::template($this->templates->find($found, $template) ?? throw ApiProblem::notFound(\sprintf('No template "%s" in "%s".', $template, $project))));
    }

    /** The scope check comes first, so a project the token cannot see answers 403 whether or not it exists. */
    private function find(string $name): Project
    {
        $this->authorizer->require(ApiScope::Read, $name);

        return $this->projects->findByName($name) ?? throw ApiProblem::notFound(\sprintf('No project "%s".', $name));
    }
}
