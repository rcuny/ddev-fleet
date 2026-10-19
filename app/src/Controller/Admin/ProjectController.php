<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Port\NamedPort;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\Rule\CommandLines;
use App\Fleet\Projects\Rule\TextBlock;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Projects\ValidationFailed;
use App\Form\Project\ProjectType;
use App\Form\Project\ValidationErrorMapper;
use App\Security\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin project pages: list, create, the General tab (edit), the read-only Ports tab and delete. All rules live
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

        $form = $this->createForm(ProjectType::class, null, ['mode' => ProjectType::CREATE]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            $hostnames = $this->hostnames($form, $data);
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
            $hostnames = $this->hostnames($form, $data);
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

    #[Route('/admin/projects/{name}/ports', name: 'project_ports', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function ports(string $name): Response
    {
        $project = $this->load($name);

        $assigned = array_map(static fn (NamedPort $port): string => $port->name(), $project->ports());

        return $this->render('admin/projects/ports.html.twig', [
            'project' => $project,
            'catalogue' => $this->ports->list(),
            'assigned' => $assigned,
        ]);
    }

    #[Route('/admin/projects/{name}/delete', name: 'project_delete', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function delete(Request $request, string $name): Response
    {
        $project = $this->load($name);
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->projects->delete($name);
        } catch (Refused $refusal) {
            $this->addFlash('warning', 'The project was not deleted. '.$refusal->getMessage());

            return $this->redirectToRoute('project_edit', ['name' => $project->name()], Response::HTTP_SEE_OTHER);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        $this->addFlash('success', \sprintf('Project "%s" deleted.', $name));

        return $this->redirectToRoute('projects', [], Response::HTTP_SEE_OTHER);
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

    /**
     * The hostnames textarea as a list. The raw text is bounded first (a megabyte must not be split); a refused text is a
     * field error and the method returns null.
     *
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $data
     *
     * @return list<string>|null
     */
    private function hostnames(FormInterface $form, array $data): ?array
    {
        $text = $data['additionalHostnames'] ?? '';
        $text = \is_string($text) ? $text : '';
        if (null !== $error = TextBlock::error($text)) {
            $form->get('additionalHostnames')->addError(new FormError($error));

            return null;
        }

        return CommandLines::normalise($text);
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
