<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Domain\Ssh\SshKeyRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Fleet\System\OutputTail;
use App\Setup\SetupActor;
use App\Setup\SetupCookie;
use App\Setup\SetupFinisher;
use App\Setup\SetupProgress;
use App\Setup\SetupRefused;
use App\Setup\SetupStep;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Step 5: a summary without secret values, then Finish. Finish runs every side effect inside this request (it can
 * take a minute or two; the php-fpm pool allows SetupFinisher::REQUIRED_REQUEST_TIMEOUT seconds) and answers with the
 * result page in the same response. A failure shows its (redacted) reason and leaves setup open for another try.
 */
final class FinishController extends AbstractSetupController
{
    public function __construct(
        private readonly HostSettingsRepository $settings,
        private readonly SetupProgress $progress,
        private readonly SshKeyRepository $keys,
        private readonly SetupFinisher $finisher,
        private readonly SetupActor $actor,
    ) {
    }

    #[Route('/setup/finish', name: 'setup_finish', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $blocking = $this->progress->firstIncomplete();
        if (SetupStep::Finish !== $blocking) {
            $this->addFlash('warning', \sprintf('Finish this step first: %s.', $blocking->label()));

            return $this->redirectToRoute($blocking->route());
        }

        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->csrfValid($request)) {
                return $this->csrfRefused();
            }

            // A closed tab must not stop Finish between two of its steps.
            ignore_user_abort(true);
            @set_time_limit(SetupFinisher::REQUIRED_REQUEST_TIMEOUT);

            try {
                $result = $this->finisher->finish($this->actor->name());

                $response = $this->page('setup/done.html.twig', SetupStep::Finish, ['result' => $result]);
                $response->headers->clearCookie(SetupCookie::NAME, '/', null, true, true, 'strict');

                return $response;
            } catch (SetupRefused $e) {
                $error = $e->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                // SetupFinisher logged it. Messages from the setup steps carry a redacted output tail at most.
                $error = OutputTail::text($e->getMessage());
                $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            } catch (\Throwable) {
                $error = 'Finish failed unexpectedly. See the application log.';
                $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            }
        }

        return $this->page('setup/finish.html.twig', SetupStep::Finish, ['error' => $error] + $this->summary(), $status);
    }

    /**
     * @return array<string, mixed> everything the page shows; no secret value, only whether one is stored
     */
    private function summary(): array
    {
        $host = $this->settings->get() ?? throw $this->createNotFoundException();
        $admin = $this->progress->admin();
        $fingerprints = [];
        foreach (SshKeyRole::cases() as $role) {
            $fingerprints[$role->value] = $this->keys->findByRole($role)?->fingerprint();
        }

        return [
            'domain' => $host->domain(),
            'tls_mode' => $host->tlsMode(),
            'tls_label' => TlsMode::OvhDns === $host->tlsMode() ? 'DNS challenge through OVH (DNS-01)' : 'Automatic per host name (HTTP-01)',
            'admin' => $admin,
            'fingerprints' => $fingerprints,
            'claude_set' => $this->progress->claudeTokenStored(),
        ];
    }
}
