<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Secrets\RevealThrottled;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Secrets\SecretName;
use App\Fleet\Secrets\SecretOpenFailed;
use App\Fleet\Secrets\SecretService;
use App\Security\CurrentActor;
use App\Security\ProjectVoter;
use App\Security\Reauth\ReauthGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The secrets pages: the fleet-wide list (`/admin/secrets`) and the Secrets tab of a project. A secret is write-only:
 * lists show names and who set them when, never a value. The one place a value leaves the server is the reveal
 * response (admin, POST with CSRF, audited, never cached). All rules live in SecretService; every action is behind the
 * `^/admin` access rule and asks ProjectVoter::SETTINGS as well (defence in depth).
 */
final class SecretController extends AbstractController
{
    private const string PROJECT = '[a-z0-9-]+';
    private const string SECRET = '[A-Z][A-Z0-9_]{0,63}';

    public function __construct(
        private readonly SecretService $secrets,
        private readonly ProjectService $projects,
        private readonly CurrentActor $actor,
        private readonly ReauthGuard $reauth,
        private readonly TemplateService $templates,
        private readonly AuditLog $audit,
        private readonly ProjectRepository $projectRepository,
    ) {
    }

    #[Route('/admin/secrets', name: 'secrets', methods: ['GET'])]
    public function fleetIndex(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);

        return $this->page(null, null, ['name' => self::queryName($request)]);
    }

    #[Route('/admin/secrets', name: 'secret_put', methods: ['POST'])]
    public function fleetPut(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);

        return $this->put($request, null);
    }

    #[Route('/admin/secrets/{secret}/delete', name: 'secret_delete', requirements: ['secret' => self::SECRET], methods: ['POST'])]
    public function fleetDelete(Request $request, string $secret): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);

        return $this->delete($request, null, $secret);
    }

    #[Route('/admin/secrets/{secret}/reveal', name: 'secret_reveal', requirements: ['secret' => self::SECRET], methods: ['POST'])]
    public function fleetReveal(Request $request, string $secret): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);

        return $this->reveal($request, null, $secret);
    }

    /**
     * The old Secrets address: it is the Keys & secrets tab now (the query string, e.g. the Replace link, is kept).
     */
    #[Route('/admin/projects/{name}/secrets', name: 'project_secrets', requirements: ['name' => self::PROJECT], methods: ['GET'])]
    public function legacyIndex(Request $request, string $name): Response
    {
        return $this->redirect($this->generateUrl('project_keys', ['name' => $name]).self::queryOf($request), Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/admin/projects/{name}/keys', name: 'project_keys', requirements: ['name' => self::PROJECT], methods: ['GET'])]
    public function projectIndex(Request $request, string $name): Response
    {
        $project = $this->load($name);

        return $this->page($project, null, ['name' => self::queryName($request)]);
    }

    /**
     * Turns terminal input over the HTTP API (`terminal:write` tokens) on or off for the project (FLE-286). ON needs the dialog's
     * `confirm=1` and a recent re-auth, like Git over SSH; OFF needs neither. Deliberately not a ProjectInput field: no CLI
     * option, export or import can switch it on. Every change is audited, a repeat of the stored value is not.
     */
    #[Route('/admin/projects/{name}/keys/api-terminal', name: 'project_keys_api_terminal', requirements: ['name' => self::PROJECT], methods: ['POST'])]
    public function apiTerminal(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $this->requireCsrf($request);
        $enable = '1' === $request->request->get('allow_api_terminal');

        if ($enable === $project->allowApiTerminal()) {
            $this->addFlash('success', 'No change: terminal input over the API was already '.($enable ? 'on' : 'off').'.');

            return $this->back($project);
        }
        if ($enable) {
            if ('1' !== $request->request->get('confirm')) {
                $this->addFlash('warning', 'Not changed: turning on terminal input needs the confirmation.');

                return $this->back($project);
            }
            if (!$this->reauth->requireRecentAuth()) {
                return $this->reauth->challenge('api-terminal', $name, '/admin/projects/'.$name.'/keys');
            }
        }

        $project->changeAllowApiTerminal($enable);
        $this->projectRepository->save($project);
        if ($enable) {
            $this->reauth->close();
        }
        $this->audit->record($this->actor->name(), AuditLog::PROJECT_API_TERMINAL, $name.'/'.($enable ? 'on' : 'off'));
        $this->addFlash('success', 'Terminal input over the API is now '.($enable ? 'on' : 'off').'.');

        return $this->back($project);
    }

    /**
     * Lets the project's compose overrides do what the deploy guard refuses (Docker socket, privileged, host namespaces, host
     * mounts; FLE-298). ON needs the dialog's `confirm=1` and a recent re-auth; OFF needs neither. Deliberately not a ProjectInput
     * field: no CLI option, export or import can switch it on. Every change is audited, a repeat of the stored value is not.
     */
    #[Route('/admin/projects/{name}/keys/privileged-overrides', name: 'project_keys_privileged_overrides', requirements: ['name' => self::PROJECT], methods: ['POST'])]
    public function privilegedOverrides(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $this->requireCsrf($request);
        $enable = '1' === $request->request->get('allow_privileged_overrides');

        if ($enable === $project->allowPrivilegedOverrides()) {
            $this->addFlash('success', 'No change: privileged overrides were already '.($enable ? 'on' : 'off').'.');

            return $this->back($project);
        }
        if ($enable) {
            if ('1' !== $request->request->get('confirm')) {
                $this->addFlash('warning', 'Not changed: turning on privileged overrides needs the confirmation.');

                return $this->back($project);
            }
            if (!$this->reauth->requireRecentAuth()) {
                return $this->reauth->challenge('privileged-overrides', $name, '/admin/projects/'.$name.'/keys');
            }
        }

        $project->changeAllowPrivilegedOverrides($enable);
        $this->projectRepository->save($project);
        if ($enable) {
            $this->reauth->close();
        }
        $this->audit->record($this->actor->name(), AuditLog::PROJECT_PRIVILEGED_OVERRIDES, $name.'/'.($enable ? 'on' : 'off'));
        $this->addFlash('success', 'Privileged overrides are now '.($enable ? 'on' : 'off').'.');

        return $this->back($project);
    }

    /**
     * Turns the per-instance ssh-agent with the push key on or off. Turning it ON is as sensitive as revealing a secret:
     * the server needs `confirm=1` (the dialog's button sends it; a crafted request without it changes nothing) and a recent
     * re-auth. Turning it off needs neither. The change is audited, a repeat of the stored value is not.
     */
    #[Route('/admin/projects/{name}/keys/ssh', name: 'project_keys_ssh', requirements: ['name' => self::PROJECT], methods: ['POST'])]
    public function containerSsh(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $this->requireCsrf($request);
        $enable = '1' === $request->request->get('container_ssh');

        if ($enable === $project->containerSsh()) {
            $this->addFlash('success', 'No change: Git over SSH in the container was already '.($enable ? 'on' : 'off').'.');

            return $this->back($project);
        }

        if ($enable) {
            if ('1' !== $request->request->get('confirm')) {
                $this->addFlash('warning', 'Not changed: turning on Git over SSH needs the confirmation.');

                return $this->back($project);
            }
            if (!$this->reauth->requireRecentAuth()) {
                return $this->reauth->challenge('container-ssh', $name, '/admin/projects/'.$name.'/keys');
            }
        }

        try {
            $this->projects->update($name, ProjectInput::fromProject($project)->with(['containerSsh' => $enable]));
        } catch (ValidationFailed $failed) {
            $this->addFlash('warning', 'Not changed: '.implode(' ', array_values($failed->errors)));

            return $this->back($project);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        if ($enable) {
            $this->reauth->close();
        }
        $this->audit->record($this->actor->name(), AuditLog::PROJECT_CONTAINER_SSH, $name.'/'.($enable ? 'on' : 'off'));
        $this->addFlash('success', 'Git over SSH in the container is now '.($enable ? 'on' : 'off').'.');

        return $this->back($project);
    }

    #[Route('/admin/projects/{name}/secrets', name: 'project_secret_put', requirements: ['name' => self::PROJECT], methods: ['POST'])]
    public function projectPut(Request $request, string $name): Response
    {
        $project = $this->load($name);

        return $this->put($request, $project);
    }

    #[Route('/admin/projects/{name}/secrets/{secret}/delete', name: 'project_secret_delete', requirements: ['name' => self::PROJECT, 'secret' => self::SECRET], methods: ['POST'])]
    public function projectDelete(Request $request, string $name, string $secret): Response
    {
        $project = $this->load($name);

        return $this->delete($request, $project, $secret);
    }

    #[Route('/admin/projects/{name}/secrets/{secret}/reveal', name: 'project_secret_reveal', requirements: ['name' => self::PROJECT, 'secret' => self::SECRET], methods: ['POST'])]
    public function projectReveal(Request $request, string $name, string $secret): Response
    {
        $project = $this->load($name);

        return $this->reveal($request, $project, $secret);
    }

    private function put(Request $request, ?Project $project): Response
    {
        $this->requireCsrf($request);
        $submitted = $request->request->all();
        // A multi-line value comes from the textarea, a single line from the masked input.
        $multiline = $submitted['value_multiline'] ?? null;
        if (('' === ($submitted['value'] ?? '')) && \is_string($multiline)) {
            $submitted['value'] = $multiline;
        }
        $name = $submitted['name'] ?? null;
        $shown = \is_string($name) ? $name : '';

        try {
            $this->secrets->put($project?->name(), $name, $submitted['value'] ?? null, $this->actor->name());
        } catch (ValidationFailed $failed) {
            // The value is never sent back; the name is, so the person does not retype it.
            return $this->page($project, null, ['name' => $shown], $failed->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SecretConflict) {
            $this->addFlash('warning', 'The secret was changed at the same moment. Try again.');

            return $this->back($project);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        $this->addFlash('success', \sprintf('Secret "%s" saved.', $shown));

        return $this->back($project);
    }

    private function delete(Request $request, ?Project $project, string $secret): Response
    {
        $this->requireCsrf($request);

        try {
            $this->secrets->delete($project?->name(), $secret, $this->actor->name());
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        $this->addFlash('success', \sprintf('Secret "%s" deleted.', $secret));

        return $this->back($project);
    }

    private function reveal(Request $request, ?Project $project, string $secret): Response
    {
        $this->requireCsrf($request);
        if (!$this->reauth->requireRecentAuth()) {
            // Before the throttle: a stopped attempt opens nothing and must not use up the reveal quota.
            return $this->reauth->challenge('reveal', SecretService::subject($project?->name(), $secret), null === $project ? '/admin/secrets' : '/admin/projects/'.$project->name().'/keys');
        }

        try {
            $value = $this->secrets->reveal($project?->name(), $secret, $this->actor->name());
        } catch (NotFound) {
            throw new NotFoundHttpException();
        } catch (RevealThrottled $e) {
            $response = $this->page($project, null, status: Response::HTTP_TOO_MANY_REQUESTS, retryAfter: $e->retryAfter);
            $response->headers->set('Retry-After', (string) $e->retryAfter);

            return $response;
        } catch (SecretOpenFailed) {
            $this->addFlash('warning', \sprintf('The secret "%s" could not be opened. Set it again.', $secret));

            return $this->back($project);
        }

        return $this->page($project, ['name' => $secret, 'value' => $value]);
    }

    /**
     * @param array{name: string, value: string}|null $revealed
     * @param array{name: string}                     $form
     * @param array<string, string>                   $errors
     */
    private function page(?Project $project, ?array $revealed, array $form = ['name' => ''], array $errors = [], int $status = Response::HTTP_OK, ?int $retryAfter = null): Response
    {
        try {
            $overview = $this->secrets->overview($project?->name());
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        $response = $this->render(null === $project ? 'admin/secrets/fleet.html.twig' : 'admin/secrets/project.html.twig', [
            'project' => $project,
            'overview' => $overview,
            'hostSecrets' => null === $project ? $this->secrets->hostSecrets() : [],
            'revealed' => $revealed,
            'form' => $form,
            'errors' => $errors,
            'retryAfter' => $retryAfter,
            'templates' => null === $project ? [] : $this->templates->list($project->name()),
        ], new Response(status: $status));
        if (null !== $revealed) {
            // The one response that carries a value: no browser or proxy may keep it.
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function back(?Project $project): Response
    {
        return null === $project
            ? $this->redirectToRoute('secrets', [], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('project_keys', ['name' => $project->name()], Response::HTTP_SEE_OTHER);
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
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

    /** `?name=` prefills the form of a "Replace" link; only a valid name is ever echoed back. */
    private static function queryName(Request $request): string
    {
        $name = $request->query->get('name');

        return \is_string($name) && null === SecretName::error($name) ? $name : '';
    }

    /**
     * The request's query string with its leading `?`, or nothing. Built here because a query key may equal a route
     * parameter (`name` is both the project and the secret to replace).
     */
    private static function queryOf(Request $request): string
    {
        $query = $request->server->get('QUERY_STRING');

        return \is_string($query) && '' !== $query ? '?'.$query : '';
    }
}
