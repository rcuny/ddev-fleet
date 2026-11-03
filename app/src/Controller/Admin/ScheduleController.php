<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Job\JobTrigger;
use App\Domain\Schedule\ScheduleOutcome;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Schedules\ScheduleInput;
use App\Fleet\Schedules\ScheduleService;
use App\Security\CurrentActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The schedules pages (admin only): list, create, edit, pause / resume, run now, delete and the run history. All rules
 * live in ScheduleService; this class maps forms to its input and its errors to field errors and flashes.
 */
final class ScheduleController extends AbstractController
{
    private const string NAME = '[a-z0-9-]+';
    private const int HISTORY_LIMIT = 50;

    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly CurrentActor $actor,
    ) {
    }

    #[Route('/admin/schedules', name: 'schedules', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $rows = [];
        foreach ($this->schedules->list() as $schedule) {
            $last = $this->schedules->lastRun($schedule);
            $rows[] = [
                'schedule' => $schedule,
                'next' => $this->schedules->nextFire($schedule),
                'last' => $last,
                'result' => null === $last ? null : $this->schedules->result($last),
                'targetMissing' => $this->schedules->targetMissing($schedule),
            ];
        }

        return $this->render('admin/schedules/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/admin/schedules/new', name: 'schedule_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = ['name' => '', 'cron' => '', 'timezone' => 'UTC', 'action' => 'redeploy', 'instance' => ''];
        if ($request->isMethod('POST')) {
            $this->requireCsrf($request);
            $form = $this->readForm($request);
            try {
                $schedule = $this->schedules->create($this->input($form), $this->actor->name());
            } catch (ValidationFailed $failed) {
                return $this->formPage(null, $form, $failed->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('success', \sprintf('Schedule "%s" created.', $schedule->name()));
            $this->warnIfTargetMissing($schedule);

            return $this->back();
        }

        return $this->formPage(null, $form);
    }

    #[Route('/admin/schedules/{name}/edit', name: 'schedule_edit', requirements: ['name' => self::NAME], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $name): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $schedule = $this->load($name);

        $form = [
            'name' => $schedule->name(),
            'cron' => $schedule->cron(),
            'timezone' => $schedule->timezone(),
            'action' => $schedule->action(),
            'instance' => $schedule->instanceId() ?? '',
        ];
        if ($request->isMethod('POST')) {
            $this->requireCsrf($request);
            $form = $this->readForm($request);
            $form['name'] = $schedule->name();
            try {
                $schedule = $this->schedules->update($name, $this->input($form), $this->actor->name());
            } catch (ValidationFailed $failed) {
                return $this->formPage($schedule->name(), $form, $failed->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
            } catch (NotFound) {
                throw new NotFoundHttpException();
            }
            $this->addFlash('success', \sprintf('Schedule "%s" saved.', $name));
            $this->warnIfTargetMissing($schedule);

            return $this->back();
        }

        return $this->formPage($schedule->name(), $form);
    }

    #[Route('/admin/schedules/{name}/pause', name: 'schedule_pause', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function pause(Request $request, string $name): Response
    {
        $this->guardPost($request);
        try {
            $this->schedules->pause($name, $this->actor->name());
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
        $this->addFlash('success', \sprintf('Schedule "%s" paused.', $name));

        return $this->back();
    }

    #[Route('/admin/schedules/{name}/resume', name: 'schedule_resume', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function resume(Request $request, string $name): Response
    {
        $this->guardPost($request);
        try {
            $this->schedules->resume($name, $this->actor->name());
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
        $this->addFlash('success', \sprintf('Schedule "%s" resumed.', $name));

        return $this->back();
    }

    #[Route('/admin/schedules/{name}/run', name: 'schedule_run', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function run(Request $request, string $name): Response
    {
        $this->guardPost($request);
        try {
            $run = $this->schedules->runNow($name, JobTrigger::Ui, $this->actor->name());
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }

        if (ScheduleOutcome::Skipped === $run->outcome() || ScheduleOutcome::Failed === $run->outcome()) {
            $this->addFlash('error', \sprintf('Schedule "%s" did not run: %s', $name, $run->detail() ?? $run->outcome()->value));
        } elseif (null !== $run->jobId()) {
            $this->addFlash('success', \sprintf('Schedule "%s" started job #%d.', $name, $run->jobId()));
        } else {
            $this->addFlash('success', \sprintf('Schedule "%s" ran.', $name));
        }

        return $this->back();
    }

    #[Route('/admin/schedules/{name}/delete', name: 'schedule_delete', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function delete(Request $request, string $name): Response
    {
        $this->guardPost($request);
        try {
            $this->schedules->delete($name, $this->actor->name());
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
        $this->addFlash('success', \sprintf('Schedule "%s" deleted.', $name));

        return $this->back();
    }

    #[Route('/admin/schedules/{name}/history', name: 'schedule_history', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function history(string $name): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $schedule = $this->load($name);

        $rows = [];
        foreach ($this->schedules->history($name, self::HISTORY_LIMIT) as $run) {
            $rows[] = ['run' => $run, 'result' => $this->schedules->result($run)];
        }

        return $this->render('admin/schedules/history.html.twig', ['schedule' => $schedule, 'rows' => $rows, 'limit' => self::HISTORY_LIMIT]);
    }

    /**
     * @param array{name: string, cron: string, timezone: string, action: string, instance: string} $form
     * @param array<string, string>                                                                 $errors
     */
    private function formPage(?string $editing, array $form, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/schedules/form.html.twig', [
            'editing' => $editing,
            'form' => $form,
            'errors' => $errors,
            'actions' => $this->schedules->actionNames(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ], new Response(status: $status));
    }

    /**
     * @return array{name: string, cron: string, timezone: string, action: string, instance: string}
     */
    private function readForm(Request $request): array
    {
        $field = static function (string $key) use ($request): string {
            $value = $request->request->all()[$key] ?? '';

            return \is_string($value) ? $value : '';
        };

        return [
            'name' => $field('name'),
            'cron' => $field('cron'),
            'timezone' => $field('timezone'),
            'action' => $field('action'),
            'instance' => $field('instance'),
        ];
    }

    /**
     * @param array{name: string, cron: string, timezone: string, action: string, instance: string} $form
     */
    private function input(array $form): ScheduleInput
    {
        return new ScheduleInput($form['name'], $form['cron'], $form['timezone'], $form['action'], $form['instance']);
    }

    private function warnIfTargetMissing(\App\Domain\Schedule\Schedule $schedule): void
    {
        if (null !== $warning = $this->schedules->targetWarning($schedule)) {
            $this->addFlash('warning', $warning);
        }
    }

    private function guardPost(Request $request): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->requireCsrf($request);
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function load(string $name): \App\Domain\Schedule\Schedule
    {
        try {
            return $this->schedules->get($name);
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
    }

    private function back(): Response
    {
        return $this->redirectToRoute('schedules', [], Response::HTTP_SEE_OTHER);
    }
}
