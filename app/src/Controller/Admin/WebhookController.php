<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Webhooks\WebhookService;
use App\Fleet\Webhooks\WebhookUrls;
use App\Security\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Webhooks page (every project's receivers and the latest deliveries), a project's Webhooks tab (its receivers and
 * its delivery log), the secret create / rotate action and the Bitbucket pipeline token set / remove action. Admin only: the `^/admin` rule, and the voter again here.
 * A secret is shown once, in the response to the POST that made it (never in a redirect, a flash or a later page).
 */
final class WebhookController extends AbstractController
{
    private const string NAME = '[a-z0-9-]+';
    private const int OVERVIEW_ROWS = 25;
    private const int PAGE_ROWS = 50;
    private const array RESULTS = [WebhookDelivery::ACCEPTED, WebhookDelivery::FAILED, WebhookDelivery::IGNORED, WebhookDelivery::DUPLICATE];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectRepository $projectRepository,
        private readonly WebhookRepository $webhooks,
        private readonly WebhookService $service,
        private readonly WebhookUrls $urls,
    ) {
    }

    #[Route('/admin/webhooks', name: 'webhooks', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);
        $hideIgnored = '1' === $request->query->get('hide-ignored');
        [$source, $result] = self::filters($request);

        return $this->render('admin/webhooks/index.html.twig', [
            'rows' => $this->rows($this->projectRepository->all(), $request),
            'deliveries' => $this->webhooks->deliveries(null, $hideIgnored, self::OVERVIEW_ROWS, 0, $source, $result),
            'hideIgnored' => $hideIgnored,
            'filter' => ['source' => $source?->value, 'result' => $result],
            'results' => self::RESULTS,
        ]);
    }

    #[Route('/admin/projects/{name}/webhooks', name: 'project_webhooks', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function project(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $hideIgnored = '1' === $request->query->get('hide-ignored');
        $page = max(1, $request->query->getInt('page', 1));
        [$source, $result] = self::filters($request);
        $deliveries = $this->webhooks->deliveries($name, $hideIgnored, self::PAGE_ROWS + 1, ($page - 1) * self::PAGE_ROWS, $source, $result);

        return $this->render('admin/webhooks/project.html.twig', [
            'project' => $project,
            'rows' => $this->rows([$project], $request),
            'deliveries' => \array_slice($deliveries, 0, self::PAGE_ROWS),
            'hasMore' => \count($deliveries) > self::PAGE_ROWS,
            'page' => $page,
            'hideIgnored' => $hideIgnored,
            'filter' => ['source' => $source?->value, 'result' => $result],
            'results' => self::RESULTS,
        ]);
    }

    /**
     * Sets, replaces or removes the Bitbucket pipeline token. The value is read from the POST and never rendered back:
     * the answer is a redirect with a flash that names no value.
     */
    #[Route('/admin/projects/{name}/webhooks/bitbucket/token', name: 'webhook_token', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function token(Request $request, string $name): Response
    {
        $this->load($name);
        $csrf = $request->request->get('_token');
        if (!\is_string($csrf) || !$this->isCsrfTokenValid('submit', $csrf)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            if ('1' === $request->request->get('clear')) {
                $this->service->clearPipelineToken($name);
                $this->addFlash('success', 'The pipeline token was removed.');
            } else {
                $value = $request->request->get('token');
                $this->service->setPipelineToken($name, \is_string($value) ? $value : '');
                $this->addFlash('success', 'The pipeline token is saved. It cannot be shown again.');
            }
        } catch (NotFound $e) {
            $this->addFlash('warning', $e->getMessage());
        } catch (ValidationFailed $e) {
            $this->addFlash('warning', $e->errors['token'] ?? 'The token was refused.');
        }

        return $this->redirectToRoute('project_webhooks', ['name' => $name]);
    }

    #[Route('/admin/projects/{name}/webhooks/{source}/secret', name: 'webhook_secret', requirements: ['name' => self::NAME, 'source' => 'jira|bitbucket'], methods: ['POST'])]
    public function secret(Request $request, string $name, string $source): Response
    {
        $project = $this->load($name);
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('submit', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $kind = WebhookSource::from($source);

        try {
            $secret = $this->service->issueSecret($name, $kind, '1' === $request->request->get('rotate'));
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        $response = $this->render('admin/webhooks/secret.html.twig', [
            'project' => $project,
            'source' => $kind,
            'secret' => $secret,
            'url' => $this->urls->url($kind, $name, $request->getSchemeAndHttpHost()),
        ]);
        // The one page that carries a secret: never stored by a browser or a proxy.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array{WebhookSource|null, string|null} the `source` and `result` query filters; anything unknown is no filter
     */
    private static function filters(Request $request): array
    {
        $source = $request->query->get('source');
        $result = $request->query->get('result');

        return [
            \is_string($source) ? WebhookSource::tryFrom($source) : null,
            \is_string($result) && \in_array($result, self::RESULTS, true) ? $result : null,
        ];
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
     * One row per project and source: the address, whether a secret exists, the rules and the newest delivery.
     *
     * @param list<Project> $projects
     *
     * @return list<array{project: Project, source: WebhookSource, url: string, endpoint: \App\Domain\Webhook\WebhookEndpoint|null, latest: WebhookDelivery|null}>
     */
    private function rows(array $projects, Request $request): array
    {
        $endpoints = [];
        foreach ($this->webhooks->endpoints() as $endpoint) {
            $endpoints[$endpoint->project()->name().'/'.$endpoint->source()->value] = $endpoint;
        }
        $latest = $this->webhooks->latestDeliveries();

        $rows = [];
        foreach ($projects as $project) {
            foreach (WebhookSource::cases() as $source) {
                $key = $project->name().'/'.$source->value;
                $rows[] = [
                    'project' => $project,
                    'source' => $source,
                    'url' => $this->urls->url($source, $project->name(), $request->getSchemeAndHttpHost()),
                    'endpoint' => $endpoints[$key] ?? null,
                    'latest' => $latest[$key] ?? null,
                ];
            }
        }

        return $rows;
    }
}
