<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectLinkService;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Security\CurrentActor;
use App\Security\ProjectVoter;
use App\Security\Reauth\ReauthGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * FLE-299: create and delete the links of a project (the Links card of its Network tab). Admin only (ProjectVoter::SETTINGS).
 * A create is a sensitive change (it opens a network path between two projects), so it asks for a recent re-authentication
 * and sends the user back to the tab; a refused create returns to the tab with the messages per field (session flash).
 * All rules and the audit trail live in ProjectLinkService.
 */
final class ProjectLinkController extends AbstractController
{
    private const string NAME = '[a-z0-9-]+';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectLinkService $links,
        private readonly CurrentActor $actor,
        private readonly ReauthGuard $reauth,
    ) {
    }

    #[Route('/admin/projects/{name}/links', name: 'project_link_create', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function create(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $this->requireCsrf($request);
        $back = $this->redirectToRoute('project_network', ['name' => $name], Response::HTTP_SEE_OTHER);
        if (!$this->reauth->requireRecentAuth()) {
            return $this->reauth->challenge('project-link-create', $name, '/admin/projects/'.$name.'/network');
        }

        $data = $request->request->all('project_link');
        $text = static fn (string $key): string => \is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        $port = $text('port');
        try {
            $this->links->create($project->name(), $text('target'), $text('service'), ctype_digit($port) && \strlen($port) <= 5 ? (int) $port : 0, $text('label'), $text('note'), $this->actor->name());
        } catch (ValidationFailed $failed) {
            $bag = $request->getSession();
            \assert($bag instanceof FlashBagAwareSessionInterface);
            $bag->getFlashBag()->set('link_errors', [$failed->errors]);
            $bag->getFlashBag()->set('link_old', [['target' => $text('target'), 'service' => $text('service'), 'port' => $port, 'label' => $text('label'), 'note' => $text('note')]]);

            return $back;
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
        $this->reauth->close();
        $this->addFlash('success', 'Link created.');

        return $back;
    }

    #[Route('/admin/projects/{name}/links/delete', name: 'project_link_delete', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function delete(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $this->requireCsrf($request);
        $text = static fn (string $key): string => \is_string($request->request->get($key)) ? trim((string) $request->request->get($key)) : '';
        $port = $text('port');
        try {
            $this->links->delete($project->name(), $text('target'), $text('service'), ctype_digit($port) ? (int) $port : 0, $text('label'), $this->actor->name());
            $this->addFlash('success', 'Link deleted.');
        } catch (Refused $refusal) {
            $this->addFlash('warning', $refusal->getMessage());
        } catch (NotFound) {
            $this->addFlash('warning', 'There is no such link.');
        }

        return $this->redirectToRoute('project_network', ['name' => $name], Response::HTTP_SEE_OTHER);
    }

    private function load(string $name): Project
    {
        $project = $this->projects->findByName($name) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS, $project);

        return $project;
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
