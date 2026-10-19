<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectService;
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
 * its delivery log) and the secret create / rotate action. Admin only: the `^/admin` rule, and the voter again here.
 * A secret is shown once, in the response to the POST that made it (never in a redirect, a flash or a later page).
 */
final class WebhookController extends AbstractController
{
    private const string NAME = '[a-z0-9-]+';
    private const int OVERVIEW_ROWS = 25;
    private const int PAGE_ROWS = 50;

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

        return $this->render('admin/webhooks/index.html.twig', [
            'rows' => $this->rows($this->projectRepository->all(), $request),
            'deliveries' => $this->webhooks->deliveries(null, $hideIgnored, self::OVERVIEW_ROWS),
            'hideIgnored' => $hideIgnored,
        ]);
    }

    #[Route('/admin/projects/{name}/webhooks', name: 'project_webhooks', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function project(Request $request, string $name): Response
    {
        $project = $this->load($name);
        $hideIgnored = '1' === $request->query->get('hide-ignored');
        $page = max(1, $request->query->getInt('page', 1));
        $deliveries = $this->webhooks->deliveries($name, $hideIgnored, self::PAGE_ROWS + 1, ($page - 1) * self::PAGE_ROWS);

        return $this->render('admin/webhooks/project.html.twig', [
            'project' => $project,
            'rows' => $this->rows([$project], $request),
            'deliveries' => \array_slice($deliveries, 0, self::PAGE_ROWS),
            'hasMore' => \count($deliveries) > self::PAGE_ROWS,
            'page' => $page,
            'hideIgnored' => $hideIgnored,
        ]);
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
     * @return list<array{project: Project, source: WebhookSource, url: string, endpoint: \App\Domain\Webhook\WebhookEndpoint|null, latest: \App\Domain\Webhook\WebhookDelivery|null}>
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
