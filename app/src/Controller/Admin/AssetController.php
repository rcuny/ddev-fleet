<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Project\Project;
use App\Fleet\Assets\AssetListing;
use App\Fleet\Assets\AssetPath;
use App\Fleet\Assets\AssetStore;
use App\Fleet\Assets\ByteSize;
use App\Fleet\Assets\UploadService;
use App\Fleet\Assets\UploadState;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Security\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Assets tab of a project: the file browser (a live read of the project's assets directory, so a file copied in over
 * SSH shows after a reload: "rescan"), download, delete, new folder, and the chunked upload endpoints the Stimulus
 * controller `asset-upload` talks to. All the rules are in AssetStore, AssetPath and UploadService; this class maps
 * requests to them and their errors to pages, flashes and JSON.
 *
 * Every action is behind the `^/admin` access rule and asks ProjectVoter::SETTINGS for the project (admin only).
 * Forms carry the stateless CSRF token as `_token`; the JSON endpoints take it from the `X-CSRF-Token` header.
 */
final class AssetController extends AbstractController
{
    private const string NAME = '[a-z0-9-]+';
    private const string ID = '[0-9a-f]{32}';

    public function __construct(
        private readonly ProjectService $projects,
        private readonly AssetStore $assets,
        private readonly UploadService $uploads,
        private readonly FleetPaths $paths,
    ) {
    }

    #[Route('/admin/projects/{name}/assets', name: 'project_assets', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function index(Request $request, string $name): Response
    {
        $project = $this->load($name);

        $problem = null;
        try {
            $listing = $this->assets->list($name, $this->queryPath($request));
        } catch (ValidationFailed|NotFound) {
            throw new NotFoundHttpException('That folder does not exist.');
        } catch (Refused $refusal) {
            // A symbolic link on the way: say so, and show the top of the project instead (if even that is refused, nothing).
            $problem = $refusal->getMessage();
            try {
                $listing = $this->assets->list($name);
            } catch (Refused) {
                $listing = new AssetListing([], [], false);
            }
        }

        return $this->render('admin/projects/assets.html.twig', [
            'project' => $project,
            'listing' => $listing,
            'problem' => $problem,
            'maxBytes' => $this->uploads->maxBytes(),
            'chunkBytes' => UploadService::MAX_CHUNK_BYTES,
            'directory' => $this->paths->assetsDir($name),
        ]);
    }

    #[Route('/admin/projects/{name}/assets/download', name: 'project_asset_download', requirements: ['name' => self::NAME], methods: ['GET'])]
    public function download(Request $request, string $name): Response
    {
        $this->load($name);

        try {
            $file = $this->assets->open($name, $this->queryPath($request));
        } catch (ValidationFailed|NotFound) {
            throw new NotFoundHttpException('That file does not exist.');
        } catch (Refused $refusal) {
            throw new HttpException(Response::HTTP_CONFLICT, $refusal->getMessage());
        }

        $response = new StreamedResponse(static function () use ($file): void {
            @set_time_limit(0);
            while (!feof($file->handle)) {
                $block = fread($file->handle, 1_048_576);
                if (false === $block || '' === $block) {
                    break;
                }
                echo $block;
                flush();
            }
            fclose($file->handle);
        });
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Length', (string) $file->size);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $file->name,
            str_replace(['%', '/', '\\'], '_', preg_replace('/[^\x20-\x7E]/', '_', $file->name) ?? 'download'),
        ));

        return $response;
    }

    #[Route('/admin/projects/{name}/assets/folders', name: 'project_asset_folder', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function folder(Request $request, string $name): Response
    {
        $this->load($name);
        $this->requireCsrf($request);
        $path = (string) $request->request->get('path', '');

        try {
            $this->assets->createFolder($name, $path, (string) $request->request->get('name', ''));
            $this->addFlash('success', 'Folder created.');
        } catch (ValidationFailed $failed) {
            $this->addFlash('warning', 'The folder was not created. '.implode(' ', $failed->errors));
        } catch (Refused $refusal) {
            $this->addFlash('warning', 'The folder was not created. '.$refusal->getMessage());
        }

        return $this->back($name, $path);
    }

    #[Route('/admin/projects/{name}/assets/delete', name: 'project_asset_delete', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function delete(Request $request, string $name): Response
    {
        $this->load($name);
        $this->requireCsrf($request);
        $path = (string) $request->request->get('path', '');

        try {
            $this->assets->delete($name, $path);
            $this->addFlash('success', 'Deleted.');
        } catch (ValidationFailed $failed) {
            $this->addFlash('warning', 'Nothing was deleted. '.implode(' ', $failed->errors));
        } catch (NotFound|Refused $refusal) {
            $this->addFlash('warning', 'Nothing was deleted. '.$refusal->getMessage());
        }

        $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

        return $this->back($name, $parent);
    }

    /**
     * Body: JSON `{path, name, size, chunkSize?, fingerprint?, overwrite?}`. Answers the upload's state; an upload with
     * the same fingerprint that is already staged is resumed.
     */
    #[Route('/admin/projects/{name}/assets/uploads', name: 'project_asset_upload_begin', requirements: ['name' => self::NAME], methods: ['POST'])]
    public function begin(Request $request, string $name): Response
    {
        $this->load($name);

        return $this->answer($request, function () use ($request, $name): array {
            $body = json_decode($request->getContent(), true);
            if (!\is_array($body)) {
                throw new ValidationFailed(['body' => 'The request body must be a JSON object.']);
            }
            $size = $body['size'] ?? null;
            $chunkSize = $body['chunkSize'] ?? null;
            if (!\is_int($size) || (null !== $chunkSize && !\is_int($chunkSize))
                || !\is_string($body['name'] ?? null) || !\is_string($body['path'] ?? '') || !\is_string($body['fingerprint'] ?? '')) {
                throw new ValidationFailed(['body' => 'Send path, name, size and fingerprint as text and whole numbers.']);
            }

            return $this->payload($this->uploads->begin(
                $name,
                (string) ($body['path'] ?? ''),
                $body['name'],
                $size,
                $chunkSize,
                (string) ($body['fingerprint'] ?? ''),
                true === ($body['overwrite'] ?? false),
            ));
        });
    }

    #[Route('/admin/projects/{name}/assets/uploads/{id}', name: 'project_asset_upload_status', requirements: ['name' => self::NAME, 'id' => self::ID], methods: ['GET'])]
    public function status(Request $request, string $name, string $id): Response
    {
        $this->load($name);

        return $this->answer($request, fn (): array => $this->payload($this->uploads->status($name, $id)), csrf: false);
    }

    /**
     * Body: the chunk's bytes (`application/octet-stream`), exactly the length the upload's state promises.
     */
    #[Route('/admin/projects/{name}/assets/uploads/{id}/chunks/{index}', name: 'project_asset_upload_chunk', requirements: ['name' => self::NAME, 'id' => self::ID, 'index' => '\d{1,5}'], methods: ['POST'])]
    public function chunk(Request $request, string $name, string $id, int $index): Response
    {
        $this->load($name);

        return $this->answer($request, fn (): array => $this->payload($this->uploads->putChunk($name, $id, $index, $request->getContent(true))));
    }

    #[Route('/admin/projects/{name}/assets/uploads/{id}/complete', name: 'project_asset_upload_complete', requirements: ['name' => self::NAME, 'id' => self::ID], methods: ['POST'])]
    public function complete(Request $request, string $name, string $id): Response
    {
        $this->load($name);

        return $this->answer($request, function () use ($name, $id): array {
            $result = $this->uploads->complete($name, $id);

            return $result->toArray() + ['sizeLabel' => ByteSize::format($result->size)];
        });
    }

    #[Route('/admin/projects/{name}/assets/uploads/{id}/abort', name: 'project_asset_upload_abort', requirements: ['name' => self::NAME, 'id' => self::ID], methods: ['POST'])]
    public function abort(Request $request, string $name, string $id): Response
    {
        $this->load($name);

        return $this->answer($request, function () use ($name, $id): array {
            $this->uploads->abort($name, $id);

            return ['aborted' => true];
        });
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

    private function queryPath(Request $request): string
    {
        return (string) $request->query->get('path', '');
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->csrfValid($request)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function csrfValid(Request $request): bool
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        return \is_string($token) && $this->isCsrfTokenValid('submit', $token);
    }

    private function back(string $name, string $path): Response
    {
        // Only a folder the rules allow goes into the URL: after a refusal the person lands on the top, not on a 404.
        try {
            AssetPath::segments($path);
        } catch (ValidationFailed) {
            $path = '';
        }
        $parameters = ['name' => $name];
        if ('' !== $path) {
            $parameters['path'] = $path;
        }

        return $this->redirectToRoute('project_assets', $parameters, Response::HTTP_SEE_OTHER);
    }

    /**
     * Runs a JSON endpoint: the CSRF header first, then the action, and its three error kinds as JSON with a status.
     *
     * @param callable(): array<string, mixed> $action
     */
    private function answer(Request $request, callable $action, bool $csrf = true): JsonResponse
    {
        if ($csrf && !$this->csrfValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token. Reload the page.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $response = new JsonResponse($action());
        } catch (ValidationFailed $failed) {
            $response = new JsonResponse(['error' => implode(' ', $failed->errors), 'fields' => $failed->errors], Response::HTTP_BAD_REQUEST);
        } catch (NotFound $missing) {
            $response = new JsonResponse(['error' => $missing->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (Refused $refusal) {
            $response = new JsonResponse(
                ['error' => $refusal->getMessage()] + (null === $refusal->reason ? [] : ['reason' => $refusal->reason]),
                0 !== $refusal->getCode() ? $refusal->getCode() : Response::HTTP_CONFLICT
            );
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(UploadState $state): array
    {
        return $state->toArray();
    }
}
