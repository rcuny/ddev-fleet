<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Instances\DeployInput;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceNaming;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Instances\OperationOptions;
use App\Fleet\Instances\Selector;
use App\Fleet\Instances\VisibleInstances;
use App\Fleet\Jobs\LiveFeedCookie;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Form\Instance\DeployType;
use App\Form\Project\ValidationErrorMapper;
use App\Security\CurrentActor;
use App\Security\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The instance pages: list with filters and bulk actions, the deploy form (admin), the detail page and the actions.
 * Instances are read through VisibleInstances, so one the user may not operate is a 404, never a 403. Every POST needs
 * the stateless CSRF token. Nothing here starts a process: InstanceService queues the jobs.
 *
 * `instance_new` and `instance_bulk` are declared before the `{id}` routes (they do not match the id grammar anyway).
 */
final class InstanceController extends AbstractController
{
    private const string ID = '[a-z0-9]+(?:-[a-z0-9]+)*--[a-z0-9]+(?:-[a-z0-9]+)*';
    private const string ACTIONS = 'start|stop|redeploy|destroy';
    private const array OPERATIONS = ['start', 'stop', 'redeploy', 'destroy'];

    public function __construct(
        private readonly VisibleInstances $visible,
        private readonly InstanceService $service,
        private readonly InstanceHostnames $hostnames,
        private readonly JobRepository $jobs,
        private readonly ProjectRepository $projects,
        private readonly TemplateRepository $templates,
        private readonly CurrentActor $actor,
        private readonly LiveFeedCookie $feedCookie,
    ) {
    }

    #[Route('/instances', name: 'instances', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = mb_strtolower(trim($request->query->getString('q')));
        $project = $request->query->getString('project');
        $stateName = $request->query->getString('state');
        $state = '' === $stateName ? null : InstanceState::tryFrom($stateName);

        $all = $this->visible->all();
        $matching = array_values(array_filter($all, static function (Instance $instance) use ($q, $project, $stateName, $state): bool {
            return ('' === $q || str_contains($instance->id(), $q))
                && ('' === $project || $instance->project()->name() === $project)
                && ('' === $stateName || $instance->state() === $state);
        }));

        $latest = $this->jobs->latestPerInstance(array_map(static fn (Instance $i): string => $i->id(), $matching));
        $rows = [];
        foreach ($matching as $instance) {
            $rows[] = [
                'instance' => $instance,
                'job' => $latest[$instance->id()] ?? null,
                'url' => $this->siteUrl($instance),
                'canDestroy' => $this->isGranted(ProjectVoter::DESTROY, $instance->project()),
            ];
        }

        $projectNames = array_values(array_unique(array_map(static fn (Instance $i): string => $i->project()->name(), $all)));
        sort($projectNames);

        $response = $this->render('instances/index.html.twig', [
            'rows' => $rows,
            'total' => \count($all),
            'projectNames' => $projectNames,
            'states' => InstanceState::cases(),
            'filters' => ['q' => $request->query->getString('q'), 'project' => $project, 'state' => $stateName],
            'canDeploy' => $this->isGranted(ProjectVoter::DEPLOY),
            'canDestroy' => $this->isGranted('ROLE_ADMIN'),
        ]);
        if (!$request->headers->has('Turbo-Frame')) {
            $this->feedCookie->attach($request, $response, ['/instances']);
        }

        return $response;
    }

    #[Route('/instances/new', name: 'instance_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::DEPLOY);

        $projects = $this->projects->all();
        $names = array_map(static fn ($project): string => $project->name(), $projects);
        $posted = $request->request->all('deploy');
        $asked = \is_string($posted['project'] ?? null) ? $posted['project'] : $request->query->getString('project');
        $selected = \in_array($asked, $names, true) ? $asked : ($names[0] ?? null);
        $project = null === $selected ? null : $this->projects->findByName($selected);

        $templateNames = null === $project ? [] : array_map(static fn ($template): string => $template->name(), $this->templates->forProject($project));
        $form = $this->createForm(DeployType::class, ['project' => $selected, 'count' => 1], [
            'projects' => $names,
            'templates' => $templateNames,
            'branch_placeholder' => $project?->defaultBranch() ?? '',
            'reload_url' => $this->generateUrl('instance_new'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $selected) {
            $data = $form->getData();
            try {
                $jobs = $this->service->requestDeploy(new DeployInput(
                    project: $selected,
                    template: self::optionalText($data['template'] ?? null),
                    branch: self::optionalText($data['branch'] ?? null),
                    label: self::optionalText($data['label'] ?? null),
                    count: \is_int($data['count'] ?? null) ? $data['count'] : 1,
                    parallel: true === ($data['parallel'] ?? false),
                ), JobTrigger::Ui, $this->actor->name());

                if (1 === \count($jobs)) {
                    return $this->redirectToRoute('job_show', ['id' => $jobs[0]->id()], Response::HTTP_SEE_OTHER);
                }
                $this->addFlash('success', \sprintf('%d jobs queued.', \count($jobs)));

                return $this->redirectToRoute('instances', [], Response::HTTP_SEE_OTHER);
            } catch (ValidationFailed $failed) {
                ValidationErrorMapper::add($form, $failed);
            } catch (Refused $refusal) {
                $form->addError(new FormError($refusal->getMessage()));
            } catch (NotFound) {
                throw new NotFoundHttpException();
            }
        }

        return $this->render('instances/new.html.twig', ['form' => $form, 'hasProjects' => [] !== $names]);
    }

    #[Route('/instances/bulk', name: 'instance_bulk', methods: ['POST'])]
    public function bulk(Request $request): Response
    {
        $this->assertCsrf($request);
        $action = $request->request->getString('action');
        if (!\in_array($action, self::OPERATIONS, true)) {
            throw new BadRequestHttpException('Unknown action.');
        }
        $ids = [];
        foreach ($request->request->all('ids') as $id) {
            if (\is_string($id)) {
                $ids[$id] = $id;
            }
        }
        if ([] === $ids) {
            $this->addFlash('warning', 'Select at least one instance first.');

            return $this->redirectToRoute('instances', [], Response::HTTP_SEE_OTHER);
        }

        // Every id must be one the user may operate, or the whole request is refused (404 hides foreign ids).
        $instances = [];
        foreach ($ids as $id) {
            $instances[] = $this->instance($id);
        }
        foreach ($instances as $instance) {
            $this->denyAccessUnlessGranted('destroy' === $action ? ProjectVoter::DESTROY : ProjectVoter::OPERATE, $instance->project());
        }

        try {
            $jobs = $this->service->request($action, new Selector(ids: array_values($ids)), new OperationOptions(), JobTrigger::Ui, $this->actor->name());
        } catch (Refused|ValidationFailed $failed) {
            $this->addFlash('warning', self::reason($failed));

            return $this->redirectToRoute('instances', [], Response::HTTP_SEE_OTHER);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
        $this->addFlash('success', \sprintf('%d %s queued.', \count($jobs), 1 === \count($jobs) ? 'job' : 'jobs'));

        return $this->redirectToRoute('instances', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/instances/{id}', name: 'instance_show', requirements: ['id' => self::ID], methods: ['GET'])]
    public function show(Request $request, string $id): Response
    {
        $instance = $this->instance($id);
        $jobs = $this->jobs->forInstance($id);
        $active = null;
        foreach ($jobs as $job) {
            if (!$job->status()->isFinal()) {
                $active = $job;
                break;
            }
        }

        $aliases = [];
        try {
            $aliases = $this->hostnames->aliases($instance->project(), $id);
        } catch (Refused|ValidationFailed) {
            // No domain yet, or an alias label that is too long: the primary URL (if any) is still shown.
        }

        $response = $this->render('instances/show.html.twig', [
            'instance' => $instance,
            'url' => $this->siteUrl($instance),
            'aliases' => $aliases,
            'jobs' => $jobs,
            'active' => $active,
            'latest' => $jobs[0] ?? null,
            'canDestroy' => $this->isGranted(ProjectVoter::DESTROY, $instance->project()),
        ]);
        if (null !== $active) {
            $this->feedCookie->attach($request, $response, ['/jobs/'.$active->id()]);
        }

        return $response;
    }

    #[Route('/instances/{id}/{action}', name: 'instance_action', requirements: ['id' => self::ID, 'action' => self::ACTIONS], methods: ['POST'])]
    public function action(Request $request, string $id, string $action): Response
    {
        $instance = $this->instance($id);
        $this->denyAccessUnlessGranted('destroy' === $action ? ProjectVoter::DESTROY : ProjectVoter::OPERATE, $instance->project());
        $this->assertCsrf($request);

        try {
            $jobs = $this->service->request($action, new Selector(ids: [$id]), new OperationOptions(), JobTrigger::Ui, $this->actor->name());
        } catch (Refused|ValidationFailed $failed) {
            $this->addFlash('warning', self::reason($failed));

            return $this->redirectToRoute('instance_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        return $this->redirectToRoute('job_show', ['id' => $jobs[0]->id()], Response::HTTP_SEE_OTHER);
    }

    private function instance(string $id): Instance
    {
        if (!InstanceNaming::isInstanceId($id)) {
            throw new NotFoundHttpException();
        }

        return $this->visible->find($id) ?? throw new NotFoundHttpException();
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function siteUrl(Instance $instance): ?string
    {
        try {
            return $this->hostnames->siteUrl($instance->id());
        } catch (Refused) {
            return null;
        }
    }

    private static function reason(Refused|ValidationFailed $failed): string
    {
        return $failed instanceof Refused ? $failed->getMessage() : implode(' ', $failed->errors);
    }

    private static function optionalText(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
