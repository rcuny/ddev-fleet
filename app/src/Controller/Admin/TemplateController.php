<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Project\Project;
use App\Domain\Template\Template;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Projects\ValidationFailed;
use App\Form\Project\TemplateType;
use App\Form\Project\TextLines;
use App\Form\Project\ValidationErrorMapper;
use App\Security\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin template pages of a project: list, create, edit and delete. All rules live in TemplateService; this class maps
 * forms to inputs and service errors to field errors. Every action asks ProjectVoter::SETTINGS itself (the `^/admin`
 * access rule is the first line, this is the second) and every state change is a POST that checks the CSRF token.
 *
 * `template_new` is declared before `template_edit`: `new` also matches the name pattern.
 */
final class TemplateController extends AbstractController
{
    private const string NAME = '[a-z0-9-]+';

    public function __construct(
        private readonly ProjectService $projects,
        private readonly TemplateService $templates,
    ) {
    }

    #[Route('/admin/projects/{name}/templates', name: 'project_templates', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function index(string $name): Response
    {
        $project = $this->project($name);

        return $this->render('admin/projects/templates/index.html.twig', [
            'project' => $project,
            'templates' => $this->templates->list($name),
        ]);
    }

    #[Route('/admin/projects/{name}/templates/new', name: 'template_new', requirements: ['name' => self::NAME], methods: ['GET', 'POST'])]
    public function new(Request $request, string $name): Response
    {
        $project = $this->project($name);

        $form = $this->createForm(TemplateType::class, null, ['mode' => TemplateType::CREATE]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            $lists = $this->lists($form, $data);
            if (null !== $lists) {
                try {
                    $this->templates->create($name, new TemplateInput(
                        name: self::text($data['name'] ?? null),
                        drupalEnv: self::optionalText($data['drupalEnv'] ?? null),
                        postDeploy: $lists['postDeploy'],
                        tty1: $lists['tty1'],
                        tty2: $lists['tty2'],
                    ));
                    $this->addFlash('success', 'Template created.');

                    return $this->redirectToRoute('project_templates', ['name' => $name], Response::HTTP_SEE_OTHER);
                } catch (ValidationFailed $failed) {
                    ValidationErrorMapper::add($form, $failed);
                } catch (NotFound) {
                    throw new NotFoundHttpException();
                }
            }
        }

        return $this->render('admin/projects/templates/form.html.twig', ['project' => $project, 'template' => null, 'form' => $form]);
    }

    #[Route('/admin/projects/{name}/templates/{template}', name: 'template_edit', requirements: ['name' => self::NAME, 'template' => self::NAME], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $name, string $template): Response
    {
        $project = $this->project($name);
        $stored = $this->template($name, $template);

        $form = $this->createForm(TemplateType::class, [
            'drupalEnv' => $stored->drupalEnv(),
            'postDeploy' => implode("\n", $stored->postDeploy()),
            'tty1' => implode("\n", $stored->tty1()),
            'tty2' => implode("\n", $stored->tty2()),
        ], ['mode' => TemplateType::EDIT]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            $lists = $this->lists($form, $data);
            if (null !== $lists) {
                try {
                    $this->templates->update($name, $template, new TemplateInput(
                        name: $stored->name(),
                        drupalEnv: self::optionalText($data['drupalEnv'] ?? null),
                        postDeploy: $lists['postDeploy'],
                        tty1: $lists['tty1'],
                        tty2: $lists['tty2'],
                    ));
                    $this->addFlash('success', 'Template saved.');

                    return $this->redirectToRoute('project_templates', ['name' => $name], Response::HTTP_SEE_OTHER);
                } catch (ValidationFailed $failed) {
                    ValidationErrorMapper::add($form, $failed);
                } catch (NotFound) {
                    throw new NotFoundHttpException();
                }
            }
        }

        return $this->render('admin/projects/templates/form.html.twig', ['project' => $project, 'template' => $stored, 'form' => $form]);
    }

    #[Route('/admin/projects/{name}/templates/{template}/delete', name: 'template_delete', requirements: ['name' => self::NAME, 'template' => self::NAME], methods: ['POST'])]
    public function delete(Request $request, string $name, string $template): Response
    {
        $this->project($name);
        $this->template($name, $template);
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->templates->delete($name, $template);
        } catch (Refused $refusal) {
            $this->addFlash('warning', 'The template was not deleted. '.$refusal->getMessage());

            return $this->redirectToRoute('project_templates', ['name' => $name], Response::HTTP_SEE_OTHER);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        $this->addFlash('success', 'Template deleted.');

        return $this->redirectToRoute('project_templates', ['name' => $name], Response::HTTP_SEE_OTHER);
    }

    private function project(string $name): Project
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);
        try {
            $project = $this->projects->get($name);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS, $project);

        return $project;
    }

    private function template(string $project, string $name): Template
    {
        try {
            return $this->templates->get($project, $name);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
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
     * The three command textareas as lists (CRLF-safe, blank lines dropped). Every field is checked so all refusals show at
     * once; null when any text was refused (the errors are already on the form).
     *
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $data
     *
     * @return array{postDeploy: list<string>, tty1: list<string>, tty2: list<string>}|null
     */
    private function lists(FormInterface $form, array $data): ?array
    {
        $postDeploy = TextLines::split($form, 'postDeploy', $data);
        $tty1 = TextLines::split($form, 'tty1', $data);
        $tty2 = TextLines::split($form, 'tty2', $data);
        if (null === $postDeploy || null === $tty1 || null === $tty2) {
            return null;
        }

        return ['postDeploy' => $postDeploy, 'tty1' => $tty1, 'tty2' => $tty2];
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function optionalText(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
