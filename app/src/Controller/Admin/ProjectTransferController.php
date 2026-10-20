<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Fleet\Export\ExportFormat;
use App\Fleet\Export\InvalidExport;
use App\Fleet\Export\PassphraseSealer;
use App\Fleet\Export\ProjectExporter;
use App\Fleet\Export\ProjectImporter;
use App\Fleet\Projects\NotFound;
use App\Security\CurrentActor;
use App\Security\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Moving a project between hosts: the export download on the project page and the Import page (upload, preview,
 * confirm). The rules and the writing live in ProjectExporter and ProjectImporter; this class maps requests to them.
 * Administrators only (ProjectVoter::SETTINGS, on top of the `^/admin` access rule); every POST carries the stateless
 * CSRF token. A passphrase is read from the form and never stored, logged or echoed back.
 *
 * The confirm step posts the file text back (a hidden field) instead of keeping it on the server: nothing is staged,
 * and the file is parsed and checked again before it is applied, so the text is as untrusted there as at the upload.
 */
final class ProjectTransferController extends AbstractController
{
    public function __construct(
        private readonly ProjectExporter $exporter,
        private readonly ProjectImporter $importer,
        private readonly CurrentActor $actor,
    ) {
    }

    #[Route('/admin/projects/{name}/export', name: 'project_export', requirements: ['name' => '[a-z0-9-]+'], methods: ['POST'])]
    public function export(Request $request, string $name): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);
        $this->requireCsrf($request);

        $passphrase = self::passphrase($request);
        try {
            $text = $this->exporter->export($name, $passphrase, $this->actor->name());
        } catch (NotFound) {
            throw new NotFoundHttpException();
        } catch (InvalidExport $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('project_edit', ['name' => $name], Response::HTTP_SEE_OTHER);
        }

        $response = new Response($text);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s.fleet-project.json"', $name));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/admin/import', name: 'import', methods: ['GET'])]
    public function form(): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);

        return $this->page();
    }

    #[Route('/admin/import/preview', name: 'import_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);
        $this->requireCsrf($request);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->page(error: 'Choose the export file to upload.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $text = ExportFormat::readFile($file->getPathname());
        } catch (InvalidExport $e) {
            return $this->page(error: $e->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->previewOf($text, self::passphrase($request));
    }

    #[Route('/admin/import/apply', name: 'import_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);
        $this->requireCsrf($request);

        $text = (string) $request->request->get('document', '');
        if ('' === $text || \strlen($text) > ExportFormat::MAX_BYTES) {
            return $this->page(error: 'The file was not received. Upload it again.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $plan = $this->importer->apply($text, self::passphrase($request), $this->actor->name());
        } catch (InvalidExport $e) {
            return $this->previewOf($text, self::passphrase($request), $e->problems);
        }

        $this->addFlash('success', \sprintf('Project "%s" imported.', $plan->project));
        foreach ($plan->warnings as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('project_edit', ['name' => $plan->project], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param list<string> $failures problems of an apply that was refused after the preview
     */
    private function previewOf(string $text, ?string $passphrase, array $failures = []): Response
    {
        $plan = $this->importer->preview($text, $passphrase);

        return $this->page(
            plan: $plan,
            document: $plan->hasErrors() ? null : $text,
            sealed: $this->importer->hasSealedSecrets($text),
            failures: $failures,
            status: $plan->hasErrors() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    /**
     * @param list<string> $failures
     */
    private function page(?\App\Fleet\Export\ImportPlan $plan = null, ?string $document = null, bool $sealed = false, ?string $error = null, array $failures = [], int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('admin/import/index.html.twig', [
            'plan' => $plan,
            'document' => $document,
            'sealed' => $sealed,
            'error' => $error,
            'failures' => $failures,
            'maxMiB' => ExportFormat::MAX_BYTES / 1024 / 1024,
            'minPassphrase' => PassphraseSealer::MIN_PASSPHRASE,
        ], new Response(status: $status));
        // The page can carry the file text; a sealed block is still not for a shared cache.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private static function passphrase(Request $request): ?string
    {
        $value = $request->request->get('passphrase');

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
