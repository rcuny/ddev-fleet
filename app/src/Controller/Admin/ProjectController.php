<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Port\NamedPort;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Auth\ProjectAccessRefresher;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\PortMirror\PortMirrorSwitch;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Projects\ValidationFailed;
use App\Form\Project\ProjectType;
use App\Form\Project\TextLines;
use App\Form\Project\ValidationErrorMapper;
use App\Security\CurrentActor;
use App\Security\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin project pages: list, create, the General tab (edit), the Ports tab (assignment) and delete. All rules live
 * in ProjectService; this class maps forms to inputs and service errors to field errors. Every action is behind the
 * `^/admin` access rule and asks ProjectVoter::SETTINGS as well (defence in depth).
 *
 * `project_new` is declared before the `{name}` routes: `new` also matches the name pattern.
 */
final class ProjectController extends AbstractController
{
    private const string NAME = '[a-z0-9-]+';

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectRepository $repository,
        private readonly TemplateService $templates,
        private readonly NamedPortService $ports,
        private readonly CurrentActor $actor,
        private readonly ProjectAccessRefresher $access,
        private readonly InstanceSiteRefresher $sites,
        private readonly PortMirrorSwitch $mirror,
    ) {
    }

    #[Route('/admin/projects', name: 'projects', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);

        return $this->render('admin/projects/index.html.twig', [
            'projects' => $this->projects->list(),
            'templateCounts' => $this->repository->templateCounts(),
        ]);
    }

    #[Route('/admin/projects/new', name: 'project_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);

        // The host default is preselected: a new project with no radio chosen showed an identity choice nobody had made.
        $form = $this->createForm(ProjectType::class, ['gitBotMode' => GitBotMode::Inherit], ['mode' => ProjectType::CREATE]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            $hostnames = TextLines::split($form, 'additionalHostnames', $data);
            if (null !== $hostnames) {
                try {
                    $project = $this->projects->create(new ProjectInput(
                        name: self::text($data['name'] ?? null),
                        git: self::text($data['git'] ?? null),
                        defaultBranch: self::optionalText($data['defaultBranch'] ?? null),
                        additionalHostnames: $hostnames,
                        gitBotMode: self::mode($data['gitBotMode'] ?? null),
                        gitBotName: self::optionalText($data['gitBotName'] ?? null),
                        gitBotEmail: self::optionalText($data['gitBotEmail'] ?? null),
                        issueIdRegexp: self::optionalText($data['issueIdRegexp'] ?? null),
                        displaySubmoduleBranch: self::optionalText($data['displaySubmoduleBranch'] ?? null),
                        typesense: true === ($data['typesense'] ?? false),
                    ));
                    $this->addFlash('success', 'Project created.');
                    $this->flashAccessWarning();

                    return $this->redirectToRoute('project_edit', ['name' => $project->name()], Response::HTTP_SEE_OTHER);
                } catch (ValidationFailed $failed) {
                    ValidationErrorMapper::add($form, $failed);
                }
            }
        }

        return $this->render('admin/projects/new.html.twig', ['form' => $form]);
    }

    #[Route('/admin/projects/{name}', name: 'project_edit', requirements: ['name' => self::NAME], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $templateNames = $this->templateNames($name);

        $form = $this->createForm(ProjectType::class, $this->formDataOf($project), ['mode' => ProjectType::EDIT, 'templates' => $templateNames]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            $hostnames = TextLines::split($form, 'additionalHostnames', $data);
            if (null !== $hostnames) {
                // Start from what is stored so the fields this tab does not show (port assignments) survive the save.
                $input = ProjectInput::fromProject($project)->with([
                    'git' => self::text($data['git'] ?? null),
                    'defaultBranch' => self::optionalText($data['defaultBranch'] ?? null),
                    'defaultTemplate' => self::optionalText($data['defaultTemplate'] ?? null),
                    'additionalHostnames' => $hostnames,
                    'gitBotMode' => self::mode($data['gitBotMode'] ?? null),
                    'gitBotName' => self::optionalText($data['gitBotName'] ?? null),
                    'gitBotEmail' => self::optionalText($data['gitBotEmail'] ?? null),
                    'issueIdRegexp' => self::optionalText($data['issueIdRegexp'] ?? null),
                    'displaySubmoduleBranch' => self::optionalText($data['displaySubmoduleBranch'] ?? null),
                    'typesense' => true === ($data['typesense'] ?? false),
                ]);
                try {
                    $this->projects->update($name, $input);
                    $this->addFlash('success', 'Project saved.');
                    $this->flashSitesWarning();

                    return $this->redirectToRoute('project_edit', ['name' => $name], Response::HTTP_SEE_OTHER);
                } catch (ValidationFailed $failed) {
                    ValidationErrorMapper::add($form, $failed);
                } catch (NotFound) {
                    throw new NotFoundHttpException();
                }
            }
        }

        return $this->render('admin/projects/edit.html.twig', [
            'project' => $project,
            'form' => $form,
            'templateNames' => $templateNames,
        ]);
    }

    #[Route('/admin/projects/{name}/ports', name: 'project_ports', requirements: ['name' => self::NAME], methods: ['GET', 'POST'])]
    public function ports(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $assigned = array_map(static fn (NamedPort $port): string => $port->name(), $project->ports());
        $errors = [];
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $selected = [];
            foreach ($request->request->all('ports') as $port) {
                if (\is_string($port)) {
                    $selected[] = $port;
                }
            }
            try {
                $this->projects->update($name, ProjectInput::fromProject($project)->with(['ports' => $selected]));
                $after = array_map(static fn (NamedPort $port): string => $port->name(), $this->projects->get($name)->ports());
                if ($after === $assigned) {
                    $this->addFlash('success', 'No change: the ports were already assigned like this.');
                } else {
                    $this->addFlash('success', 'Ports saved.');
                    $this->flashSitesWarning();
                    $this->flashMirrorWarning();
                }

                return $this->redirectToRoute('project_ports', ['name' => $name], Response::HTTP_SEE_OTHER);
            } catch (ValidationFailed $failed) {
                $errors = array_values($failed->errors);
                $assigned = $selected;
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } catch (NotFound) {
                throw new NotFoundHttpException();
            }
        }

        return $this->render('admin/projects/ports.html.twig', [
            'project' => $project,
            'catalogue' => $this->ports->list(),
            'assigned' => $assigned,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    #[Route('/admin/projects/{name}/delete', name: 'project_delete', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function delete(Request $request, string $name): Response
    {
        $project = $this->load($name);
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->projects->delete($name, $this->actor->name());
        } catch (Refused $refusal) {
            $this->addFlash('warning', 'The project was not deleted. '.$refusal->getMessage());

            return $this->redirectToRoute('project_edit', ['name' => $project->name()], Response::HTTP_SEE_OTHER);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        $this->addFlash('success', \sprintf('Project "%s" deleted.', $name));
        $this->flashAccessWarning();

        return $this->redirectToRoute('projects', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * The project change is saved, but the sign-in service may not have followed (ProjectAccessRefresher).
     */
    private function flashAccessWarning(): void
    {
        $warning = $this->access->takeWarning();
        if (null !== $warning) {
            $this->addFlash('warning', $warning);
        }
    }

    /**
     * The project change is saved, but a Caddy site may not have followed (InstanceSiteRefresher).
     */
    private function flashSitesWarning(): void
    {
        $warning = $this->sites->takeWarning();
        if (null !== $warning) {
            $this->addFlash('warning', $warning);
        }
    }

    /**
     * The port assignment is saved and the mirror files may not have followed (PortMirrorSwitch::sync()).
     */
    private function flashMirrorWarning(): void
    {
        $warning = $this->mirror->sync();
        if (null !== $warning) {
            $this->addFlash('warning', $warning);
        }
    }

    private function load(string $name): Project
    {
        try {
            $project = $this->projects->get($name);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS, $project);

        return $project;
    }

    /**
     * @return list<string>
     */
    private function templateNames(string $project): array
    {
        try {
            return array_map(static fn ($template): string => $template->name(), $this->templates->list($project));
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formDataOf(Project $project): array
    {
        return [
            'git' => $project->git(),
            'defaultBranch' => $project->defaultBranch(),
            'defaultTemplate' => $project->defaultTemplate(),
            'additionalHostnames' => implode("\n", $project->additionalHostnames()),
            'gitBotMode' => $project->gitBotMode(),
            'gitBotName' => $project->gitBotName(),
            'gitBotEmail' => $project->gitBotEmail(),
            'issueIdRegexp' => $project->issueIdRegexp(),
            'displaySubmoduleBranch' => $project->displaySubmoduleBranch(),
            'typesense' => $project->typesense(),
        ];
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return array<string, mixed>
     */
    private function formData(FormInterface $form): array
    {
        $data = $form->getData();
        if (!\is_array($data)) {
            return [];
        }

        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function optionalText(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    private static function mode(mixed $value): GitBotMode
    {
        return $value instanceof GitBotMode ? $value : GitBotMode::Inherit;
    }
}
