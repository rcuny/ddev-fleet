<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Jobs\LiveFeedCookie;
use App\Fleet\Jobs\LogReader;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Security\CurrentActor;
use App\Security\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One job: its details, its log in pieces (the live log asks `from=<byte>`) and Retry. A job is visible to an admin or
 * to anyone who may operate its project; anything else is a 404. The log file is found from the job row alone, never
 * from the request.
 */
final class JobController extends AbstractController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ProjectRepository $projects,
        private readonly LogReader $logs,
        private readonly InstanceService $instances,
        private readonly CurrentActor $actor,
        private readonly LiveFeedCookie $feedCookie,
    ) {
    }

    #[Route('/jobs/{id<\d+>}', name: 'job_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $job = $this->visibleJob($id);
        $project = $this->projectOf($job);

        $response = $this->render('jobs/show.html.twig', [
            'job' => $job,
            'canRetry' => $this->mayRetry($job, $project),
        ]);
        $this->feedCookie->attach($request, $response, ['/jobs/'.$id]);

        return $response;
    }

    #[Route('/jobs/{id<\d+>}/log', name: 'job_log', methods: ['GET'])]
    public function log(Request $request, int $id): Response
    {
        $job = $this->visibleJob($id);

        $from = $request->query->has('from') ? $request->query->getString('from') : '0';
        if (1 !== preg_match('/^\d{1,15}$/', $from)) {
            throw new BadRequestHttpException('The offset must be a non-negative integer.');
        }

        $chunk = $this->logs->read($job, (int) $from);
        $response = new JsonResponse([
            'text' => $chunk['text'],
            'next' => $chunk['next'],
            'done' => $chunk['done'],
            'status' => $job->status()->value,
            'size' => $this->logs->size($job),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/jobs/{id<\d+>}/retry', name: 'job_retry', methods: ['POST'])]
    public function retry(Request $request, int $id): Response
    {
        $job = $this->visibleJob($id);
        $project = $this->projectOf($job);
        if (null !== $project) {
            $this->denyAccessUnlessGranted('destroy' === $job->type() ? ProjectVoter::DESTROY : ProjectVoter::OPERATE, $project);
        }
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $retry = $this->instances->retry($id, JobTrigger::Ui, $this->actor->name());
        } catch (Refused $refusal) {
            $this->addFlash('warning', $refusal->getMessage());

            return $this->redirectToRoute('job_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        return $this->redirectToRoute('job_show', ['id' => $retry->id()], Response::HTTP_SEE_OTHER);
    }

    private function visibleJob(int $id): Job
    {
        $job = $this->jobs->find($id) ?? throw new NotFoundHttpException();
        $project = $this->projectOf($job);
        $visible = $this->isGranted('ROLE_ADMIN') || (null !== $project && $this->isGranted(ProjectVoter::OPERATE, $project));

        return $visible ? $job : throw new NotFoundHttpException();
    }

    private function projectOf(Job $job): ?Project
    {
        $name = $job->projectName();

        return null === $name ? null : $this->projects->findByName($name);
    }

    private function mayRetry(Job $job, ?Project $project): bool
    {
        if (JobStatus::Failed !== $job->status() && JobStatus::Interrupted !== $job->status()) {
            return false;
        }
        if (null === $project) {
            return false;
        }

        return $this->isGranted('destroy' === $job->type() ? ProjectVoter::DESTROY : ProjectVoter::OPERATE, $project);
    }
}
