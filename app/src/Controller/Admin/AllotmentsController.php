<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Fleet\Audit\AuditLog;
use App\Fleet\Host\Capacity\HostCapacity;
use App\Fleet\Projects\CapacityBatchFailed;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Rule\Capacity;
use App\Fleet\System\SlicesApplier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "Adjust allotments" sheet of the capacity tab (FLE-318): the allotments of every project as JSON, and one save
 * that applies all the changes at once (all or nothing, admin, CSRF, one audit entry per project).
 */
final class AllotmentsController extends AbstractController
{
    public function __construct(
        private readonly HostCapacity $capacity,
        private readonly ProjectService $projects,
        private readonly AuditLog $audit,
        private readonly SlicesApplier $slices,
    ) {
    }

    #[Route('/admin/host/allotments', name: 'settings_allotments', methods: ['GET'])]
    public function load(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $report = $this->capacity->report();

        $projects = [];
        foreach (array_values($report['projects']) as $index => $project) {
            $templates = [];
            $floor = ['cpu_threads' => null, 'slots' => null];
            $floorBy = ['cpu_threads' => null, 'slots' => null];
            foreach ($project['templates'] as $template) {
                $templates[] = ['name' => $template['name'], 'cpu_threads' => $template['cpu_threads'], 'slots' => $template['slots']];
                foreach (['cpu_threads', 'slots'] as $field) {
                    if (null !== $template[$field] && $template[$field] > ($floor[$field] ?? 0)) {
                        $floor[$field] = $template[$field];
                        $floorBy[$field] = $template['name'];
                    }
                }
            }
            $projects[] = [
                'name' => $project['name'],
                'colour_index' => $index,
                'cpu_threads' => $project['cpu_threads'],
                'slots' => $project['slots'],
                'effective_threads' => $project['effective_threads'],
                'templates' => $templates,
                'floor' => $floor,
                'floor_template' => $floorBy,
            ];
        }

        return $this->json([
            'schema' => 1,
            'threads' => $report['threads'],
            'reserve_threads' => $report['reserve_threads'],
            'allocatable_threads' => $report['allocatable_threads'],
            'max' => Capacity::MAX,
            'not_under_slice' => \count($report['instances']['not_under_slice'] ?? []),
            'projects' => $projects,
        ]);
    }

    #[Route('/admin/host/allotments', name: 'settings_allotments_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $token = $request->headers->get('X-CSRF-Token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('submit', $token)) {
            return $this->json(['error' => 'The request could not be verified. Reload the page and try again.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $changes = self::changes($request);
        if (null === $changes) {
            return $this->json(['error' => 'The allotments could not be read. Reload the page and try again.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $done = $this->projects->updateCapacities($changes);
        } catch (CapacityBatchFailed $failed) {
            return $this->json(['errors' => $failed->errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $actor = $this->getUser()?->getUserIdentifier() ?? 'admin';
        foreach ($done as $row) {
            $this->audit->record($actor, AuditLog::PROJECT_CAPACITY, \sprintf('%s threads %s->%s, slots %s->%s', $row['name'], self::show($row['threads'][0]), self::show($row['threads'][1]), self::show($row['slots'][0]), self::show($row['slots'][1])));
        }

        $report = $this->capacity->report();
        $message = 1 === \count($done) ? 'Allotments saved: 1 project changed.' : \sprintf('Allotments saved: %d projects changed.', \count($done));
        if ($report['overcommitted']) {
            $message .= \sprintf(' Over-allocated: %d of %d threads. Allowed: allotments are ceilings, not reservations.', $report['committed_threads'], $report['allocatable_threads']);
        }
        if (null !== $warning = $this->slices->takeWarning()) {
            $message .= ' '.$warning;
        }
        $this->addFlash('success', $message);

        return $this->json(['saved' => array_column($done, 'name'), 'redirect' => $this->generateUrl('settings', ['tab' => 'capacity'])]);
    }

    /**
     * @return list<array{name: string, cpu_threads: int|null, slots: int|null}>|null null when the body is malformed
     */
    private static function changes(Request $request): ?array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($body) || !\is_array($rows = $body['projects'] ?? null) || \count($rows) > 500) {
            return null;
        }
        $changes = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['name'] ?? null)) {
                return null;
            }
            $values = [];
            foreach (['cpu_threads', 'slots'] as $field) {
                $value = $row[$field] ?? null;
                if (null !== $value && !\is_int($value)) {
                    return null;
                }
                $values[$field] = $value;
            }
            $changes[] = ['name' => $row['name'], 'cpu_threads' => $values['cpu_threads'], 'slots' => $values['slots']];
        }

        return $changes;
    }

    private static function show(?int $value): string
    {
        return null === $value ? 'unlimited' : (string) $value;
    }
}
