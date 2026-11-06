<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Port\NamedPort;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectAccessRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\UserRepository;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Auth\AccessInstallFailed;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\IdentitySync;
use App\Fleet\Auth\ProjectAccessRefresher;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UnknownUser;
use App\Fleet\Host\Capacity\AllocatableThreads;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\PortMirror\PortMirrorSwitch;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\NetworkInput;
use App\Fleet\Projects\NetworkService;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Secrets\SecretStore;
use App\Form\Project\CapacityType;
use App\Form\Project\NetworkType;
use App\Form\Project\ProjectType;
use App\Form\Project\SourceType;
use App\Form\Project\TextLines;
use App\Form\Project\ValidationErrorMapper;
use App\Security\CurrentActor;
use App\Security\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin project pages: list, create, the Overview and the Source tab (the former General form), the Network tab (port assignment), thin pages for the tabs still to come and delete. All rules live
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
        private readonly AllocatableThreads $allocatable,
        private readonly InstanceRepository $instances,
        private readonly SecretStore $secrets,
        private readonly WebhookRepository $webhookEndpoints,
        private readonly NetworkService $network,
        private readonly ProjectAccessRepository $accessRows,
        private readonly ProjectAccessService $members,
        private readonly IdentitySync $identity,
        private readonly UserRepository $users,
        private readonly AdminGroup $admins,
        private readonly AuditLog $audit,
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
        $form = $this->createForm(ProjectType::class, ['gitBotMode' => GitBotMode::Inherit], ['allocatable' => $this->allocatable->get()]);
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
                        containerSsh: true === ($data['containerSsh'] ?? false),
                        cpuThreads: self::optionalInt($data['cpuThreads'] ?? null),
                        slots: self::optionalInt($data['slots'] ?? null),
                    ));
                    $this->addFlash('success', 'Project created.');
                    $this->flashAccessWarning();

                    return $this->redirectToRoute('project_overview', ['name' => $project->name()], Response::HTTP_SEE_OTHER);
                } catch (ValidationFailed $failed) {
                    ValidationErrorMapper::add($form, $failed);
                }
            }
        }

        return $this->render('admin/projects/new.html.twig', ['form' => $form]);
    }

    /**
     * The landing page of a project: a read-only summary with a link to every tab.
     */
    #[Route('/admin/projects/{name}', name: 'project_overview', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function overview(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $allocatable = $this->allocatable->get();
        // Only this project's endpoints, and the secret names without the asset scan SecretService::overview() runs.
        $webhooks = [];
        foreach (WebhookSource::cases() as $source) {
            if (null !== $this->webhookEndpoints->endpoint($name, $source)) {
                $webhooks[$source->value] = true;
            }
        }

        return $this->render('admin/projects/overview.html.twig', [
            'project' => $project,
            'counts' => [
                'instances' => \count($this->instances->byProject($project)),
                'templates' => \count($this->templateNames($name)),
                'ports' => \count($project->ports()),
                'secrets' => \count($this->secrets->listManaged($name)),
            ],
            'webhooks' => $webhooks,
            'allocatable' => $allocatable,
            'effectiveThreads' => min($project->cpuThreads() ?? $allocatable, $allocatable),
        ]);
    }

    #[Route('/admin/projects/{name}/access', name: 'project_access', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function access(string $name): Response
    {
        $project = $this->load($name);
        $members = array_flip($this->accessRows->usernamesOf($project));
        $memberRows = [];
        $adminRows = [];
        $candidates = [];
        $adminNames = [];
        foreach ($this->users->all() as $user) {
            if ($this->admins->includes($user)) {
                $adminNames[] = $user->username();
            }
            if (isset($members[$user->username()])) {
                $memberRows[] = $user;
            } elseif ($this->admins->includes($user)) {
                $adminRows[] = $user;
            } else {
                $candidates[] = $user;
            }
        }

        return $this->render('admin/projects/access.html.twig', [
            'project' => $project,
            'members' => $memberRows,
            'admins' => $adminRows,
            'candidates' => $candidates,
            'adminNames' => $adminNames,
            'ports' => $project->ports(),
        ]);
    }

    #[Route('/admin/projects/{name}/access/members', name: 'project_access_add', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function addMember(Request $request, string $name): Response
    {
        return $this->changeMember($request, $name, (string) $request->request->get('username'), true);
    }

    #[Route('/admin/projects/{name}/access/members/{username}/remove', name: 'project_access_remove', requirements: ['name' => self::NAME, 'username' => '[a-z0-9][a-z0-9._-]{0,63}'], methods: ['POST'])]
    public function removeMember(Request $request, string $name, string $username): Response
    {
        return $this->changeMember($request, $name, $username, false);
    }

    /**
     * The same service and sign-in sync as the user page and `fleet:user:grant` / `fleet:user:revoke`; only the audit
     * entry is added here.
     */
    private function changeMember(Request $request, string $name, string $username, bool $add): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $project = $this->load($name);
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $changed = false;
        $warning = null;
        try {
            try {
                $changed = $this->identity->commit(fn (): bool => $add
                    ? $this->members->grant($username, $project->name())
                    : $this->members->revoke($username, $project->name()));
            } catch (AccessInstallFailed $failed) {
                $changed = true === $failed->result;
                $warning = $failed->getMessage();
            }
        } catch (UnknownUser) {
            $this->addFlash('warning', \sprintf('"%s" is not a known user.', $username));

            return $this->redirectToRoute('project_access', ['name' => $name], Response::HTTP_SEE_OTHER);
        } catch (\RuntimeException|\InvalidArgumentException $failed) {
            $this->addFlash('warning', 'Nothing was changed: '.$failed->getMessage());

            return $this->redirectToRoute('project_access', ['name' => $name], Response::HTTP_SEE_OTHER);
        }

        if ($changed) {
            $this->audit->record($this->actor->name(), $add ? AuditLog::PROJECT_MEMBER_ADD : AuditLog::PROJECT_MEMBER_REMOVE, $project->name().'/'.$username);
            $this->addFlash('success', \sprintf($add ? '"%s" is now a member of "%s".' : '"%s" is no longer a member of "%s".', $username, $project->name()));
        } else {
            $this->addFlash('success', \sprintf($add ? 'No change: "%s" was already a member.' : 'No change: "%s" was not a member.', $username));
        }
        if (null !== $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('project_access', ['name' => $name], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/projects/{name}/capacity', name: 'project_capacity', requirements: ['name' => self::NAME], methods: ['GET', 'POST'])]
    public function capacity(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $allocatable = $this->allocatable->get();
        $form = $this->createForm(CapacityType::class, ['cpuThreads' => $project->cpuThreads(), 'slots' => $project->slots()], ['allocatable' => $allocatable]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            // Start from what is stored so only the two caps change.
            $input = ProjectInput::fromProject($project)->with([
                'cpuThreads' => self::optionalInt($data['cpuThreads'] ?? null),
                'slots' => self::optionalInt($data['slots'] ?? null),
            ]);
            try {
                $this->projects->update($name, $input);
                $this->addFlash('success', 'Project saved.');

                return $this->redirectToRoute('project_capacity', ['name' => $name], Response::HTTP_SEE_OTHER);
            } catch (ValidationFailed $failed) {
                ValidationErrorMapper::add($form, $failed);
            } catch (NotFound) {
                throw new NotFoundHttpException();
            }
        }

        // Effective values as the host capacity report computes them (HostCapacity): a template inherits the project's
        // cap and is clamped by it.
        $effective = min($project->cpuThreads() ?? $allocatable, $allocatable);
        $rows = [];
        $explicitThreads = 0;
        $explicitSlots = 0;
        try {
            $templates = $this->templates->list($name);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
        foreach ($templates as $template) {
            $explicitThreads += $template->cpuThreads() ?? 0;
            $explicitSlots += $template->slots() ?? 0;
            $rows[] = [
                'name' => $template->name(),
                'threads' => $template->cpuThreads(),
                'slots' => $template->slots(),
                'effectiveThreads' => min($template->cpuThreads() ?? $effective, $effective),
                'effectiveSlots' => null === $template->slots() ? $project->slots() : (null === $project->slots() ? $template->slots() : min($template->slots(), $project->slots())),
            ];
        }

        return $this->render('admin/projects/capacity.html.twig', [
            'project' => $project,
            'form' => $form,
            'allocatable' => $allocatable,
            'effectiveThreads' => $effective,
            'rows' => $rows,
            'threadsOver' => $explicitThreads > $effective && (null !== $project->cpuThreads()),
            'slotsOver' => null !== $project->slots() && $explicitSlots > $project->slots(),
            'explicitThreads' => $explicitThreads,
            'explicitSlots' => $explicitSlots,
        ]);
    }

    #[Route('/admin/projects/{name}/transfer', name: 'project_transfer', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function transfer(string $name): Response
    {
        return $this->render('admin/projects/transfer.html.twig', ['project' => $this->load($name)]);
    }

    #[Route('/admin/projects/{name}/source', name: 'project_source', requirements: ['name' => self::NAME], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $name): Response
    {
        $project = $this->load($name);

        $form = $this->createForm(SourceType::class, $this->formDataOf($project));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            // Start from what is stored so the fields this tab does not show (ports, hostnames, Typesense, caps) survive the save.
            $input = ProjectInput::fromProject($project)->with([
                'git' => self::text($data['git'] ?? null),
                'defaultBranch' => self::optionalText($data['defaultBranch'] ?? null),
                'gitBotMode' => self::mode($data['gitBotMode'] ?? null),
                'gitBotName' => self::optionalText($data['gitBotName'] ?? null),
                'gitBotEmail' => self::optionalText($data['gitBotEmail'] ?? null),
                'issueIdRegexp' => self::optionalText($data['issueIdRegexp'] ?? null),
                'displaySubmoduleBranch' => self::optionalText($data['displaySubmoduleBranch'] ?? null),
                'containerSsh' => true === ($data['containerSsh'] ?? false),
            ]);
            try {
                $this->projects->update($name, $input);
                $this->addFlash('success', 'Project saved.');
                $this->flashSitesWarning();

                return $this->redirectToRoute('project_source', ['name' => $name], Response::HTTP_SEE_OTHER);
            } catch (ValidationFailed $failed) {
                ValidationErrorMapper::add($form, $failed);
            } catch (NotFound) {
                throw new NotFoundHttpException();
            }
        }

        return $this->render('admin/projects/edit.html.twig', [
            'project' => $project,
            'form' => $form,
        ]);
    }

    /**
     * The old Ports address: GET and POST both go to the Network tab (308 keeps the method and the body).
     */
    #[Route('/admin/projects/{name}/ports', name: 'project_ports', requirements: ['name' => self::NAME], methods: ['GET', 'POST'])]
    public function legacyPorts(string $name): Response
    {
        return $this->redirectToRoute('project_network', ['name' => $name], Response::HTTP_PERMANENTLY_REDIRECT);
    }

    /**
     * The Network tab: hostnames, Typesense, port assignment and port access. A change that exposes something (a new
     * hostname, assigning or unassigning a public port, any access change) is shown again in a confirmation dialog first;
     * its button submits the same form with `confirmed=1`, so nothing is stored twice and it works without a script.
     */
    #[Route('/admin/projects/{name}/network', name: 'project_network', requirements: ['name' => self::NAME], methods: ['GET', 'POST'])]
    public function ports(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $assigned = array_map(static fn (NamedPort $port): string => $port->name(), $project->ports());
        $selected = $assigned;
        $requested = [];
        $errors = [];
        $changes = [];
        $confirm = false;
        $status = Response::HTTP_OK;

        $form = $this->createForm(NetworkType::class, [
            'additionalHostnames' => implode("\n", $project->additionalHostnames()),
            'typesense' => $project->typesense(),
        ]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $form->handleRequest($request);
            $selected = self::strings($request->request->all('ports'));
            $requested = self::keyed($request->request->all('access'));
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;

            if (!$form->isSubmitted()) {
                $errors[] = 'The form was not sent completely. Reload the page and try again.';
            } elseif ($form->isValid()) {
                $data = $this->formData($form);
                $hostnames = TextLines::split($form, 'additionalHostnames', $data);
                if (null !== $hostnames) {
                    $input = new NetworkInput($hostnames, true === ($data['typesense'] ?? false), $selected, $requested);
                    try {
                        $this->network->validate($project, $input);
                        $changes = $this->network->plan($project, $input);
                        if (NetworkService::needsConfirmation($changes) && '1' !== $request->request->get('confirmed')) {
                            $confirm = true;
                        } else {
                            $this->network->apply($name, $input, $this->actor->name());
                            $this->addFlash('success', [] === $changes && $this->sameTypesense($project, $input) ? 'No change: the network settings were already like this.' : 'Network settings saved.');
                            $this->flashSitesWarning();
                            if ([] !== $changes || !$this->sameTypesense($project, $input)) {
                                $this->flashMirrorWarning();
                            }

                            return $this->redirectToRoute('project_network', ['name' => $name], Response::HTTP_SEE_OTHER);
                        }
                    } catch (ValidationFailed $failed) {
                        foreach ($failed->errors as $field => $message) {
                            if ($form->has((string) $field)) {
                                $form->get((string) $field)->addError(new FormError($message));
                            } else {
                                $errors[] = $message;
                            }
                        }
                    } catch (NotFound) {
                        throw new NotFoundHttpException();
                    }
                }
            }
        }

        return $this->render('admin/projects/ports.html.twig', [
            'project' => $project,
            'form' => $form,
            'catalogue' => $this->ports->list(),
            'stored' => $assigned,
            'assigned' => $selected,
            'requested' => $requested,
            'errors' => $errors,
            'confirm' => $confirm,
            'changes' => $changes,
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

            return $this->redirectToRoute('project_source', ['name' => $project->name()], Response::HTTP_SEE_OTHER);
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

    private function sameTypesense(Project $project, NetworkInput $input): bool
    {
        return $project->typesense() === $input->typesense;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, static fn (mixed $value): bool => \is_string($value)));
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    private static function keyed(array $values): array
    {
        $kept = [];
        foreach ($values as $key => $value) {
            if (\is_string($value)) {
                $kept[(string) $key] = $value;
            }
        }

        return $kept;
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
            'gitBotMode' => $project->gitBotMode(),
            'gitBotName' => $project->gitBotName(),
            'gitBotEmail' => $project->gitBotEmail(),
            'issueIdRegexp' => $project->issueIdRegexp(),
            'displaySubmoduleBranch' => $project->displaySubmoduleBranch(),
            'containerSsh' => $project->containerSsh(),
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

    private static function optionalInt(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
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
