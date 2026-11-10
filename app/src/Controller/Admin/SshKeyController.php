<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Ssh\SshKeyRole;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\Refused;
use App\Fleet\Ssh\ManagedSshKeys;
use App\Fleet\Ssh\PushKeyCheck;
use App\Fleet\Ssh\PushKeyState;
use App\Fleet\Ssh\SshKeyService;
use App\Security\CurrentActor;
use App\Security\ProjectVoter;
use App\Security\Reauth\ReauthGuard;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The changes behind the "SSH keys" view of the host settings (FLE-266): generate or import an extra key, delete one nobody
 * uses, rotate the deploy or push key. Every change is a POST with the CSRF token and needs a recent re-auth (the password
 * again); a private key pasted into the import form is never echoed, logged or put in a flash message.
 */
final class SshKeyController extends AbstractController
{
    private const string BACK = '/admin/host/ssh-keys';

    public function __construct(
        private readonly ManagedSshKeys $extra,
        private readonly SshKeyService $hostKeys,
        private readonly PushKeyCheck $pushCheck,
        private readonly \App\Domain\Project\ProjectRepository $projectRepository,
        private readonly ReauthGuard $reauth,
        private readonly CurrentActor $actor,
        private readonly AuditLog $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/host/ssh-keys/check-push', name: 'ssh_key_check_push', methods: ['POST'])]
    public function checkPush(Request $request): Response
    {
        // Read-only, so no re-auth: it only asks the git host whether the push key is registered (FLE-271).
        $this->requireCsrf($request);
        $host = strtolower(trim($request->request->getString('host')));
        $allowed = [];
        foreach ($this->projectRepository->all() as $project) {
            try {
                $allowed[] = PushKeyCheck::hostFromRemote($project->git());
            } catch (\InvalidArgumentException) {
            }
        }
        if (!\in_array($host, $allowed, true)) {
            $this->addFlash('warning', 'Pick the git host of one of the registered projects.');

            return $this->back();
        }
        try {
            $state = $this->pushCheck->run($host, true);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->back();
        }
        $this->addFlash(PushKeyState::Registered === $state ? 'success' : 'warning', $state->message(strtolower($host)));

        return $this->back();
    }

    #[Route('/admin/host/ssh-keys/create', name: 'ssh_key_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (null !== $stop = $this->gate($request, 'ssh-key-create', 'new')) {
            return $stop;
        }
        $name = $request->request->getString('name');

        try {
            $key = $this->extra->create($name, $this->actor->name());
        } catch (\InvalidArgumentException|Refused $e) {
            $this->addFlash('warning', 'Not created: '.$e->getMessage());

            return $this->back();
        } catch (\RuntimeException $e) {
            $this->logger->error('An extra SSH key could not be created.', ['exception' => $e::class]);
            $this->addFlash('warning', 'Not created: the key could not be written. See the application log.');

            return $this->back();
        }
        $this->reauth->close();
        $this->addFlash('success', \sprintf('SSH key "%s" created (%s). Register its public key where it is needed.', $name, $key->fingerprint()));

        return $this->back();
    }

    #[Route('/admin/host/ssh-keys/import', name: 'ssh_key_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (null !== $stop = $this->gate($request, 'ssh-key-import', 'new')) {
            return $stop;
        }
        $name = $request->request->getString('name');

        try {
            $key = $this->extra->import($name, $request->request->getString('private_key'), $this->actor->name());
        } catch (\InvalidArgumentException|Refused $e) {
            // Fixed sentences: the key is never repeated.
            $this->addFlash('warning', 'Not imported: '.$e->getMessage());

            return $this->back();
        } catch (\RuntimeException $e) {
            $this->logger->error('An extra SSH key could not be imported.', ['exception' => $e::class]);
            $this->addFlash('warning', 'Not imported: the key could not be written. See the application log.');

            return $this->back();
        }
        $this->reauth->close();
        $this->addFlash('success', \sprintf('SSH key "%s" imported (%s).', $name, $key->fingerprint()));

        return $this->back();
    }

    #[Route('/admin/host/ssh-keys/{name}/delete', name: 'ssh_key_delete', requirements: ['name' => '[a-z][a-z0-9-]{0,31}'], methods: ['POST'])]
    public function delete(Request $request, string $name): Response
    {
        if (null !== $stop = $this->gate($request, 'ssh-key-delete', $name)) {
            return $stop;
        }

        try {
            $this->extra->delete($name, $this->actor->name());
        } catch (Refused $e) {
            $this->addFlash('warning', 'Not deleted: '.$e->getMessage());

            return $this->back();
        }
        $this->reauth->close();
        $this->addFlash('success', \sprintf('SSH key "%s" deleted.', $name));

        return $this->back();
    }

    #[Route('/admin/host/ssh-keys/{role}/rotate', name: 'ssh_key_rotate', requirements: ['role' => 'deploy|push'], methods: ['POST'])]
    public function rotate(Request $request, string $role): Response
    {
        $key = SshKeyRole::from($role);
        if ('1' !== $request->request->get('confirm')) {
            $this->requireCsrf($request);
            $this->addFlash('warning', 'Not rotated: confirm that the old key stops working wherever it is registered.');

            return $this->back();
        }
        if (null !== $stop = $this->gate($request, 'ssh-key-rotate', $role)) {
            return $stop;
        }

        try {
            $this->hostKeys->regenerate($key, $this->actor->name());
        } catch (\RuntimeException $e) {
            $this->logger->error('An SSH key could not be rotated.', ['role' => $role, 'exception' => $e::class]);
            $this->addFlash('warning', 'Not rotated: the key could not be replaced. See the application log.');

            return $this->back();
        }
        $this->audit->record($this->actor->name(), AuditLog::SSH_KEY_ROTATE, $role);
        $this->reauth->close();
        $this->addFlash('success', \sprintf('A new %s key was generated. Register its public key; the old one no longer works.', $role));

        return $this->back();
    }

    /**
     * @return Response|null the response that stops the request (re-auth needed), null to go on
     */
    private function gate(Request $request, string $action, string $subject): ?Response
    {
        $this->requireCsrf($request);
        if (!$this->reauth->requireRecentAuth()) {
            return $this->reauth->challenge($action, $subject, self::BACK);
        }

        return null;
    }

    private function requireCsrf(Request $request): void
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function back(): Response
    {
        return $this->redirect(self::BACK, Response::HTTP_SEE_OTHER);
    }
}
