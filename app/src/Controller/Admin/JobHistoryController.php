<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The job history of the whole host: filters, newest first, 50 per page. Admin only (the `^/admin` rule, and here
 * again). A filter value that names nothing (an unknown status, trigger or type) matches no job.
 */
final class JobHistoryController extends AbstractController
{
    public const int PER_PAGE = 50;
    public const array TYPES = ['deploy', 'start', 'stop', 'redeploy', 'destroy'];
    private const int TEXT_MAX = 100;

    public function __construct(private readonly JobRepository $jobs)
    {
    }

    #[Route('/admin/jobs', name: 'jobs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $filters = [];
        foreach (['status', 'trigger', 'type', 'project', 'instance', 'q'] as $name) {
            $filters[$name] = mb_substr(trim($request->query->getString($name)), 0, self::TEXT_MAX);
        }
        $asked = $request->query->getString('page');
        $page = 1 === preg_match('/^\d{1,6}$/', $asked) ? max(1, (int) $asked) : 1;

        $status = '' === $filters['status'] ? null : JobStatus::tryFrom($filters['status']);
        $trigger = '' === $filters['trigger'] ? null : JobTrigger::tryFrom($filters['trigger']);
        $type = '' === $filters['type'] ? null : $filters['type'];
        $matchesNothing = ('' !== $filters['status'] && null === $status)
            || ('' !== $filters['trigger'] && null === $trigger)
            || (null !== $type && !\in_array($type, self::TYPES, true));

        $result = $matchesNothing
            ? ['rows' => [], 'total' => 0]
            : $this->jobs->search([
                'status' => $status,
                'trigger' => $trigger,
                'type' => $type,
                'project' => '' === $filters['project'] ? null : $filters['project'],
                'instance' => '' === $filters['instance'] ? null : $filters['instance'],
                'q' => $filters['q'],
            ], $page, self::PER_PAGE);

        return $this->render('admin/jobs/index.html.twig', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'hasNext' => $page * self::PER_PAGE < $result['total'],
            'filters' => $filters,
            'statuses' => JobStatus::cases(),
            'triggers' => JobTrigger::cases(),
            'types' => self::TYPES,
        ]);
    }
}
