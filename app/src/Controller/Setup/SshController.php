<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Domain\Ssh\SshKeyRole;
use App\Fleet\Ssh\KnownHostConflict;
use App\Fleet\Ssh\KnownHostEntry;
use App\Fleet\Ssh\KnownHostScanner;
use App\Fleet\Ssh\KnownHostScannerUnavailable;
use App\Fleet\Ssh\KnownHostsFile;
use App\Fleet\Ssh\SshKeyService;
use App\Setup\ScanTargetPolicy;
use App\Setup\SetupActor;
use App\Setup\SetupStep;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Step 3: the host's two SSH identities and the servers it trusts. The public keys are shown (and copyable) so they can
 * be registered as deploy keys before Finish. A new host is added in two steps: scan (read-only, adds nothing), then
 * trust, which scans AGAIN and adds only the keys whose fingerprint the person confirmed, never a line the browser sent.
 */
final class SshController extends AbstractSetupController
{
    private const int MAX_KEY_UPLOAD_BYTES = 16384;

    public function __construct(
        private readonly SshKeyService $keys,
        private readonly KnownHostsFile $knownHosts,
        private readonly KnownHostScanner $scanner,
        private readonly SetupActor $actor,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/setup/ssh', name: 'setup_ssh', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $outcome = ['errors' => [], 'scan' => null, 'host' => ''];

        if ($request->isMethod('POST')) {
            if (!$this->csrfValid($request)) {
                return $this->csrfRefused();
            }
            $outcome = match ($request->request->get('action')) {
                'scan' => $this->scan($request),
                'trust' => $this->trust($request),
                'regenerate' => $this->regenerate($request),
                'import' => $this->import($request),
                default => ['errors' => ['Unknown action.'], 'scan' => null, 'host' => ''],
            };
            if ([] === $outcome['errors'] && null === $outcome['scan']) {
                return $this->redirectToRoute('setup_ssh');
            }
        }

        $status = [] === $outcome['errors'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY;

        return $this->page('setup/ssh.html.twig', SetupStep::Ssh, [
            'errors' => $outcome['errors'],
            'scan' => $outcome['scan'],
            'scan_host' => $outcome['host'],
            ...$this->view(),
        ], $status);
    }

    /**
     * @return array{errors: list<string>, scan: list<array{type: string, fingerprint: string}>|null, host: string}
     */
    private function scan(Request $request): array
    {
        $host = $this->targetHost($request, $error);
        if (null === $host) {
            return $this->failed($error);
        }
        if ($this->knownHosts->has($host)) {
            return $this->failed(\sprintf('"%s" is already trusted; nothing to add.', $host));
        }

        try {
            $entries = $this->scanner->scan($host);
        } catch (KnownHostScannerUnavailable $e) {
            $this->logger->error('ssh-keyscan is unavailable.', ['exception' => $e::class]);

            return $this->failed('ssh-keyscan is not available on this server (install openssh-client), so host keys cannot be read here.');
        }
        if ([] === $entries) {
            return $this->failed(\sprintf('No SSH host key could be read from "%s". Check the name and that the server answers on that port.', $host));
        }

        return [
            'errors' => [],
            'scan' => array_map(static fn (KnownHostEntry $e): array => ['type' => $e->type, 'fingerprint' => $e->fingerprint()], $entries),
            'host' => $host,
        ];
    }

    /**
     * @return array{errors: list<string>, scan: list<array{type: string, fingerprint: string}>|null, host: string}
     */
    private function trust(Request $request): array
    {
        $host = $this->targetHost($request, $error);
        if (null === $host) {
            return $this->failed($error);
        }

        $posted = $request->request->all()['fingerprints'] ?? [];
        $confirmed = array_values(array_filter(\is_array($posted) ? $posted : [], \is_string(...)));

        try {
            $entries = array_values(array_filter(
                $this->scanner->scan($host),
                static fn (KnownHostEntry $e): bool => \in_array($e->fingerprint(), $confirmed, true),
            ));
        } catch (KnownHostScannerUnavailable $e) {
            $this->logger->error('ssh-keyscan is unavailable.', ['exception' => $e::class]);

            return $this->failed('ssh-keyscan is not available on this server, so host keys cannot be read here.');
        }
        if ([] === $entries) {
            return $this->failed(\sprintf('The keys "%s" offers now are not the ones you confirmed. Scan it again and compare the fingerprints.', $host));
        }

        try {
            $this->knownHosts->addAll($entries);
        } catch (\RuntimeException $e) {
            if ($e instanceof KnownHostConflict) {
                return $this->failed($e->getMessage());
            }
            $this->logger->error('known_hosts could not be updated.', ['exception' => $e::class]);

            return $this->failed('The known hosts file could not be updated.');
        }
        $this->addFlash('success', \sprintf('Trusted %d host key(s) for %s.', \count($entries), $host));

        return ['errors' => [], 'scan' => null, 'host' => ''];
    }

    /**
     * @return array{errors: list<string>, scan: list<array{type: string, fingerprint: string}>|null, host: string}
     */
    private function regenerate(Request $request): array
    {
        $role = SshKeyRole::tryFrom((string) $request->request->get('role'));
        if (null === $role) {
            return $this->failed('Choose which key to replace.');
        }
        if (!$this->confirmed($request)) {
            return $this->failed('Tick the box to confirm: the old key stops working wherever it is registered.');
        }

        try {
            $this->keys->regenerate($role, $this->actor->name());
        } catch (\RuntimeException $e) {
            $this->logger->error('An SSH key could not be regenerated.', ['role' => $role->value, 'exception' => $e::class]);

            return $this->failed('The key could not be replaced. See the application log.');
        }
        $this->addFlash('success', \sprintf('A new %s key was generated. Register its public key.', $role->value));

        return ['errors' => [], 'scan' => null, 'host' => ''];
    }

    /**
     * @return array{errors: list<string>, scan: list<array{type: string, fingerprint: string}>|null, host: string}
     */
    private function import(Request $request): array
    {
        $role = SshKeyRole::tryFrom((string) $request->request->get('role'));
        if (null === $role) {
            return $this->failed('Choose which key to replace.');
        }
        if (!$this->confirmed($request)) {
            return $this->failed('Tick the box to confirm: the old key stops working wherever it is registered.');
        }
        $file = $request->files->get('private_key');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->failed('Choose the private key file to import.');
        }
        if ($file->getSize() > self::MAX_KEY_UPLOAD_BYTES) {
            return $this->failed('That file is too large to be an SSH private key.');
        }
        $pem = @file_get_contents($file->getPathname());
        if (false === $pem) {
            return $this->failed('The uploaded file could not be read.');
        }

        try {
            $this->keys->importPrivate($role, $pem, $this->actor->name());
        } catch (\InvalidArgumentException $e) {
            // Fixed sentences from SshKeyService; the key itself is never repeated.
            return $this->failed($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->logger->error('An SSH key could not be imported.', ['role' => $role->value, 'exception' => $e::class]);

            return $this->failed('The key could not be imported. See the application log.');
        }
        $this->addFlash('success', \sprintf('The %s key was replaced by the imported one.', $role->value));

        return ['errors' => [], 'scan' => null, 'host' => ''];
    }

    /**
     * @param-out string $error
     */
    private function targetHost(Request $request, ?string &$error): ?string
    {
        $error = '';
        try {
            $host = ScanTargetPolicy::normalize((string) $request->request->get('host'));
        } catch (\InvalidArgumentException) {
            $error = 'Enter a host name such as git.example.com, or [git.example.com]:2222 for another port.';

            return null;
        }
        $refusal = ScanTargetPolicy::refusal($host);
        if (null !== $refusal) {
            $error = $refusal;

            return null;
        }

        return $host;
    }

    private function confirmed(Request $request): bool
    {
        return '1' === $request->request->get('confirm');
    }

    /**
     * @return array{errors: list<string>, scan: null, host: string}
     */
    private function failed(string $message): array
    {
        return ['errors' => [$message], 'scan' => null, 'host' => ''];
    }

    /**
     * What the page shows: public keys with fingerprints, and the trusted hosts. A problem reading either becomes a
     * message on the page, not an exception.
     *
     * @return array{keys: list<array{role: string, label: string, public_key: string, fingerprint: string}>, hosts: array<string, list<array{type: string, fingerprint: string}>>, problems: list<string>}
     */
    private function view(): array
    {
        $problems = [];
        $keys = [];
        try {
            $this->keys->ensureKeys();
            foreach (SshKeyRole::cases() as $role) {
                $keys[] = [
                    'role' => $role->value,
                    'label' => SshKeyRole::Deploy === $role ? 'Deploy key (clones repositories)' : 'Push key (pushes to remotes)',
                    'public_key' => $this->keys->publicKey($role),
                    'fingerprint' => $this->keys->fingerprint($role),
                ];
            }
        } catch (\RuntimeException $e) {
            $this->logger->error('The SSH keys could not be prepared.', ['exception' => $e::class]);
            $problems[] = 'The SSH keys could not be prepared. See the application log; Finish stays unavailable until they exist.';
        }

        $hosts = [];
        try {
            $this->knownHosts->seed();
            foreach ($this->knownHosts->entries() as $entry) {
                $hosts[$entry->host][] = ['type' => $entry->type, 'fingerprint' => $entry->fingerprint()];
            }
            ksort($hosts);
        } catch (\RuntimeException $e) {
            $this->logger->error('The known hosts could not be read.', ['exception' => $e::class]);
            $problems[] = 'The list of trusted hosts could not be read. See the application log.';
        }

        return ['keys' => $keys, 'hosts' => $hosts, 'problems' => $problems];
    }
}
