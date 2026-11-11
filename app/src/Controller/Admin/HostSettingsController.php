<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Backup\BackupInfo;
use App\Fleet\Backup\BackupKind;
use App\Fleet\Backup\BackupNotFound;
use App\Fleet\Backup\BackupService;
use App\Fleet\Backup\BackupTooLarge;
use App\Fleet\Host\Capacity\HostCapacity;
use App\Fleet\Host\Capacity\HostHardwareProbe;
use App\Fleet\Host\Capacity\UnslicedInstances;
use App\Fleet\Host\ClaudeTokenRule;
use App\Fleet\Host\Drift\DriftService;
use App\Fleet\Host\GitIdentityRule;
use App\Fleet\Host\HostSecrets;
use App\Fleet\Host\NotificationMailRule;
use App\Fleet\Host\Provision\FirewallSwitch;
use App\Fleet\Host\Provision\HardeningSettings;
use App\Fleet\Host\Provision\HostVarsReader;
use App\Fleet\Host\Provision\ProvisionService;
use App\Fleet\PortMirror\PortMirror;
use App\Fleet\PortMirror\PortMirrorSwitch;
use App\Fleet\Projects\Refused;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Secrets\SecretService;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Ssh\SshKeyCatalog;
use App\Fleet\System\ConfigValue;
use App\Fleet\Tls\TlsStatusFile;
use App\Fleet\Workspace\ClaudeTokenRotation;
use App\Fleet\Workspace\WorkspaceCheck;
use App\Form\Host\HostBackupRetentionType;
use App\Form\Host\HostCapacityType;
use App\Form\Host\HostClaudeTokenType;
use App\Form\Host\HostGeneralType;
use App\Form\Host\HostGitIdentityType;
use App\Form\Host\HostHardeningType;
use App\Form\Host\HostJobConcurrencyType;
use App\Form\Host\HostNotificationType;
use App\Form\Host\HostPortMirrorType;
use App\Form\Host\HostUploadLimitType;
use App\Health\HostHealthReport;
use App\Security\Reauth\ReauthGuard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The host settings page (/admin/host, administrators only: the ^/admin access rule decides): ACME contact, git bot
 * identity, Claude token, notification e-mail, the port mirror switch, the asset upload limit and database backups, one tab each. Secrets are write-only: the page
 * shows whether one is set and when it was last written, never the value, and no value reaches a flash message, a log
 * line or an exception message here.
 */
final class HostSettingsController extends AbstractController
{
    public const array TABS = [
        'general' => 'General',
        'git' => 'Git identity',
        'claude' => 'Claude token',
        'notifications' => 'Notifications',
        'network' => 'Networking',
        'certificates' => 'Certificates',
        'uploads' => 'Uploads',
        'jobs' => 'Jobs',
        'capacity' => 'Capacity',
        'ssh' => 'SSH keys',
        'hardening' => 'Hardening',
        'drift' => 'Drift',
        'backups' => 'Backups',
    ];

    public function __construct(
        private readonly HostSettingsRepository $settings,
        private readonly SecretStore $secrets,
        private readonly BackupService $backups,
        private readonly ClaudeTokenRotation $rotation,
        private readonly WorkspaceCheck $workspace,
        private readonly PortMirror $portMirror,
        private readonly PortMirrorSwitch $portMirrorSwitch,
        private readonly TlsStatusFile $tlsStatus,
        private readonly HostCapacity $capacity,
        private readonly HostHardwareProbe $hardware,
        private readonly UnslicedInstances $unsliced,
        private readonly SshKeyCatalog $sshCatalog,
        private readonly \App\Domain\Project\ProjectRepository $projectRepository,
        private readonly ProvisionService $provision,
        private readonly DriftService $drift,
        private readonly HostVarsReader $hostVars,
        private readonly FirewallSwitch $firewall,
        private readonly ReauthGuard $reauth,
        private readonly AuditLog $audit,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly \App\Fleet\Host\Docker\DockerSocketCheck $dockerSocket,
    ) {
    }

    #[Route('/admin/host', name: 'settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tab = $request->query->getString('tab');

        return $this->show(isset(self::TABS[$tab]) ? $tab : 'general', $this->settings->get());
    }

    /** The SSH keys tab at a path of its own, so the re-auth page can return to it (it only accepts paths). */
    #[Route('/admin/host/ssh-keys', name: 'settings_ssh_keys', methods: ['GET'])]
    public function sshKeys(): Response
    {
        return $this->show('ssh', $this->settings->get());
    }

    #[Route('/admin/host/general', name: 'settings_general', methods: ['POST'])]
    public function general(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('general', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $email = ConfigValue::acmeEmail($this->text($form, 'acmeEmail'));
            } catch (\InvalidArgumentException) {
                $form->get('acmeEmail')->addError(new FormError('That is not a valid e-mail address.'));

                return $this->show('general', $host, $form);
            }
            $host->changeAcmeEmail($email, $this->clock->now());
            $this->settings->save($host);
            $this->addFlash('success', 'ACME contact saved. Apply it to Caddy with "sudo fleet host:render --apply" on the host.');

            return $this->back('general');
        }

        return $this->show('general', $host, $form);
    }

    #[Route('/admin/host/git', name: 'settings_git', methods: ['POST'])]
    public function git(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('git', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = $this->text($form, 'name');
            $email = $this->text($form, 'email');
            $violations = GitIdentityRule::violations($name, $email);
            foreach ($violations as $field => $message) {
                $form->get($field)->addError(new FormError($message));
            }
            if ([] === $violations) {
                ['name' => $name, 'email' => $email] = GitIdentityRule::normalise($name, $email);
                $host->changeGitIdentity($name, $email, $this->clock->now());
                $this->settings->save($host);
                $this->addFlash('success', 'Git identity saved.');

                return $this->back('git');
            }
        }

        return $this->show('git', $host, $form);
    }

    #[Route('/admin/host/claude', name: 'settings_claude', methods: ['POST'])]
    public function claude(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('claude', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $token = trim($this->text($form, 'token') ?? '');
            if ('' === $token) {
                $form->get('token')->addError(new FormError('Paste the token to store it.'));
            } elseif (!ClaudeTokenRule::isValid($token)) {
                $form->get('token')->addError(new FormError(ClaudeTokenRule::MESSAGE));
            } else {
                try {
                    $this->rotation->rotate($token, $this->actor());
                    $this->addFlash('success', 'Claude token stored.');

                    return $this->back('claude');
                } catch (SecretConflict|\RuntimeException $e) {
                    // The class only: nothing about the value may reach the log.
                    $this->logger->error('The Claude token could not be stored.', ['exception' => $e::class]);
                    $form->addError(new FormError('The token could not be stored. Try again.'));
                }
            }
        }

        return $this->show('claude', $host, $form);
    }

    #[Route('/admin/host/notifications', name: 'settings_notifications', methods: ['POST'])]
    public function notifications(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('notifications', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $port = $form->get('smtpPort')->getData();
            $port = \is_int($port) ? $port : null;
            $violations = NotificationMailRule::violations(
                $this->text($form, 'smtpHost'),
                $port,
                $this->text($form, 'smtpUser'),
                $this->text($form, 'mailFrom'),
                $this->text($form, 'mailTo'),
            );
            foreach ($violations as $field => $message) {
                $form->get($field)->addError(new FormError($message));
            }

            $password = $this->text($form, 'smtpPassword') ?? '';
            $clear = (bool) $form->get('clearPassword')->getData();
            $passwordProblem = $clear && '' !== $password
                ? 'Type a new password or remove the stored one, not both.'
                : ('' !== $password ? NotificationMailRule::passwordViolation($password) : null);
            if (null !== $passwordProblem) {
                $form->get('smtpPassword')->addError(new FormError($passwordProblem));
            }

            if ([] === $violations && null === $passwordProblem) {
                try {
                    // The secret first: if it cannot be written nothing else changes.
                    if ($clear) {
                        $this->secrets->forgetFleetWide(HostSecrets::SMTP_PASSWORD);
                        $this->audit->record($this->actor(), AuditLog::SECRET_DELETE, SecretService::subject(null, HostSecrets::SMTP_PASSWORD));
                    } elseif ('' !== $password) {
                        $this->secrets->putFleetWide(HostSecrets::SMTP_PASSWORD, $password, $this->actor());
                        $this->audit->record($this->actor(), AuditLog::SECRET_PUT, SecretService::subject(null, HostSecrets::SMTP_PASSWORD));
                    }
                    $host->changeNotificationMail(
                        $this->text($form, 'smtpHost'),
                        $port,
                        $this->text($form, 'smtpUser'),
                        $this->text($form, 'mailFrom'),
                        $this->text($form, 'mailTo'),
                        $this->clock->now(),
                    );
                    $this->settings->save($host);
                    $this->addFlash('success', 'Notification e-mail settings saved.');

                    return $this->back('notifications');
                } catch (SecretConflict|\RuntimeException $e) {
                    $this->logger->error('The notification e-mail settings could not be stored.', ['exception' => $e::class]);
                    $form->addError(new FormError('The settings could not be stored. Try again.'));
                }
            }
        }

        return $this->show('notifications', $host, $form);
    }

    #[Route('/admin/host/network', name: 'settings_network', methods: ['POST'])]
    public function network(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('network', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $warning = $this->portMirrorSwitch->set((bool) $form->get('enabled')->getData());
            $this->addFlash('success', 'Port mirror setting saved.');
            if (null !== $warning) {
                $this->addFlash('error', $warning);
            }

            return $this->back('network');
        }

        return $this->show('network', $host, $form);
    }

    #[Route('/admin/host/uploads', name: 'settings_uploads', methods: ['POST'])]
    public function uploads(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('uploads', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $mebibytes = $form->get('megabytes')->getData();
            if (!\is_int($mebibytes) || $mebibytes < 1 || $mebibytes > HostSettings::MAX_ASSET_UPLOAD_MEBIBYTES) {
                $form->get('megabytes')->addError(new FormError(\sprintf('Enter a number of MiB from 1 to %d.', HostSettings::MAX_ASSET_UPLOAD_MEBIBYTES)));
            } else {
                $host->changeAssetUploadMaxBytes($mebibytes * 1_048_576, $this->clock->now());
                $this->settings->save($host);
                $this->addFlash('success', 'Upload limit saved.');

                return $this->back('uploads');
            }
        }

        return $this->show('uploads', $host, $form);
    }

    #[Route('/admin/host/backups', name: 'settings_backup_create', methods: ['POST'])]
    public function backupNow(Request $request): Response
    {
        if (!$this->csrfValid($request)) {
            $this->addFlash('error', 'The request could not be verified. Reload the page and try again.');

            return $this->show('backups', $this->settings->get())->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $info = $this->backups->createWithinRequestLimit();
            $this->addFlash('success', \sprintf('Backup written: %s (%s).', $info->name, HostHealthReport::bytes($info->size)));
        } catch (BackupTooLarge $e) {
            $this->addFlash('error', \sprintf(
                'The databases (%s) are too large to back up from this page. Run "fleet fleet:backup:create" on the host or wait for the daily backup.',
                HostHealthReport::bytes($e->size),
            ));
        } catch (\RuntimeException $e) {
            $this->logger->error('A database backup requested from the host page failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $this->addFlash('error', 'The backup failed. Check the free disk space and the application log.');
        }

        return $this->back('backups');
    }

    #[Route('/admin/host/jobs', name: 'settings_jobs', methods: ['POST'])]
    public function jobs(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('jobs', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $jobs = $form->get('jobs')->getData();
            if (!\is_int($jobs) || $jobs < 1 || $jobs > HostSettings::MAX_MAX_CONCURRENT_JOBS) {
                $form->get('jobs')->addError(new FormError(\sprintf('Enter a number from 1 to %d.', HostSettings::MAX_MAX_CONCURRENT_JOBS)));
            } else {
                $host->changeMaxConcurrentJobs($jobs, $this->clock->now());
                $this->settings->save($host);
                $this->addFlash('success', 'Job concurrency saved.');

                return $this->back('jobs');
            }
        }

        return $this->show('jobs', $host, $form);
    }

    #[Route('/admin/host/capacity', name: 'settings_capacity', methods: ['POST'])]
    public function capacitySave(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('capacity', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reserve = $form->get('reserve')->getData();
            $max = $form->get('maxInstances')->getData();
            $threads = $this->hardware->read()->threads;
            if (!\is_int($reserve) || $reserve < 0 || $reserve >= $threads) {
                $form->get('reserve')->addError(new FormError(\sprintf('Enter a number from 0 to %d: this host has %d threads and at least one must stay allocatable.', $threads - 1, $threads)));
            }
            if (!\is_int($max) || $max < 1 || $max > HostSettings::MAX_MAX_INSTANCES) {
                $form->get('maxInstances')->addError(new FormError(\sprintf('Enter a number from 1 to %d.', HostSettings::MAX_MAX_INSTANCES)));
            }
            if (\is_int($reserve) && \is_int($max) && 0 === $form->get('reserve')->getErrors()->count() && 0 === $form->get('maxInstances')->getErrors()->count()) {
                $host->changeCapacity($reserve, $max, $this->clock->now());
                $this->settings->save($host);
                $this->addFlash('success', 'Capacity settings saved.');

                return $this->back('capacity');
            }
        }

        return $this->show('capacity', $host, $form);
    }

    #[Route('/admin/host/hardening', name: 'settings_hardening', methods: ['POST'])]
    public function hardeningSave(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('hardening', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $settings = new HardeningSettings(
                (bool) $form->get('enabled')->getData(),
                HardeningSettings::splitList($this->text($form, 'sshUsers')),
                HardeningSettings::splitList($this->text($form, 'trustedIps')),
                \is_int($grace = $form->get('graceMinutes')->getData()) ? $grace : 0,
                (bool) $form->get('hardenTmp')->getData(),
                $this->nullIfBlank($this->text($form, 'invokingUser')),
            );
            $violations = $settings->violations();
            $fields = ['sshUsers' => 'sshUsers', 'invokingUser' => 'invokingUser', 'trustedIps' => 'trustedIps', 'graceMinutes' => 'graceMinutes'];
            foreach ($violations as $field => $message) {
                $form->get($fields[$field])->addError(new FormError($message));
            }

            if ([] === $violations) {
                // Saving changes the SSH and firewall rules of the host: the password again, once per change.
                if (!$this->reauth->requireRecentAuth()) {
                    return $this->reauth->challenge('host.hardening', 'host', '/admin/host');
                }
                try {
                    $job = $this->provision->queue($settings, $this->actor());
                    $this->reauth->close();

                    return $this->redirectToRoute('job_show', ['id' => $job->id()], Response::HTTP_SEE_OTHER);
                } catch (Refused $refusal) {
                    $form->addError(new FormError($refusal->getMessage()));
                }
            }
        }

        return $this->show('hardening', $host, $form);
    }

    #[Route('/admin/host/drift', name: 'settings_drift_run', methods: ['POST'])]
    public function runDrift(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        if (!$this->csrfValid($request)) {
            $this->addFlash('error', 'The request could not be verified. Reload the page and try again.');

            return $this->show('drift', $host)->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $job = $this->drift->queue($this->actor());
        } catch (Refused $refusal) {
            $this->addFlash('error', $refusal->getMessage());

            return $this->show('drift', $host)->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('job_show', ['id' => $job->id()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/host/backups/retention', name: 'settings_backup_retention', methods: ['POST'])]
    public function retention(Request $request): Response
    {
        if (null === $host = $this->settings->get()) {
            return $this->notInitialised();
        }
        $form = $this->form('backups', $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $days = $form->get('days')->getData();
            if (!\is_int($days) || $days < 1 || $days > HostSettings::MAX_BACKUP_RETENTION_DAYS) {
                $form->get('days')->addError(new FormError(\sprintf('Enter a number of days from 1 to %d.', HostSettings::MAX_BACKUP_RETENTION_DAYS)));
            } else {
                $host->changeBackupRetentionDays($days, $this->clock->now());
                $this->settings->save($host);
                $this->addFlash('success', 'Backup retention saved.');

                return $this->back('backups');
            }
        }

        return $this->show('backups', $host, $form);
    }

    #[Route('/admin/host/backups/{name}', name: 'settings_backup_download', methods: ['GET'], requirements: ['name' => '[^/]+'])]
    public function download(string $name): Response
    {
        try {
            // Only the fleet database is offered, as in the list; BackupService accepts nothing but its own file names.
            if (!str_starts_with($name, BackupKind::Fleet->value.'-')) {
                throw new BackupNotFound('Not a fleet database backup.');
            }
            $path = $this->backups->path($name);
        } catch (BackupNotFound) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path, Response::HTTP_OK, [], false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->headers->set('Content-Type', 'application/vnd.sqlite3');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function form(string $tab, HostSettings $host): FormInterface
    {
        return match ($tab) {
            'general' => $this->createForm(HostGeneralType::class, ['acmeEmail' => $host->acmeEmail()], ['action' => $this->generateUrl('settings_general')]),
            'git' => $this->createForm(HostGitIdentityType::class, ['name' => $host->gitUserName(), 'email' => $host->gitUserEmail()], ['action' => $this->generateUrl('settings_git')]),
            'claude' => $this->createForm(HostClaudeTokenType::class, null, [
                'stored' => $this->secrets->hasFleetWide(HostSecrets::CLAUDE_TOKEN),
                'action' => $this->generateUrl('settings_claude'),
            ]),
            'notifications' => $this->createForm(HostNotificationType::class, [
                'smtpHost' => $host->smtpHost(),
                'smtpPort' => $host->smtpPort(),
                'smtpUser' => $host->smtpUser(),
                'mailFrom' => $host->mailFrom(),
                'mailTo' => $host->mailTo(),
            ], [
                'passwordStored' => $this->secrets->hasFleetWide(HostSecrets::SMTP_PASSWORD),
                'action' => $this->generateUrl('settings_notifications'),
            ]),
            'network' => $this->createForm(HostPortMirrorType::class, ['enabled' => $host->portMirror()], ['action' => $this->generateUrl('settings_network')]),
            'uploads' => $this->createForm(HostUploadLimitType::class, ['megabytes' => intdiv($host->assetUploadMaxBytes(), 1_048_576)], ['action' => $this->generateUrl('settings_uploads')]),
            'capacity' => $this->createForm(HostCapacityType::class, ['reserve' => $host->cpuReserveThreads(), 'maxInstances' => $host->maxInstances()], ['action' => $this->generateUrl('settings_capacity')]),
            'hardening' => $this->createForm(HostHardeningType::class, $this->hardeningData(), ['action' => $this->generateUrl('settings_hardening')]),
            'jobs' => $this->createForm(HostJobConcurrencyType::class, ['jobs' => $host->maxConcurrentJobs()], ['action' => $this->generateUrl('settings_jobs')]),
            default => $this->createForm(HostBackupRetentionType::class, ['days' => $host->backupRetentionDays()], ['action' => $this->generateUrl('settings_backup_retention')]),
        };
    }

    /**
     * Renders the page on $tab; $submitted is the (invalid) form of the tab being re-shown, the others are fresh.
     *
     * @param FormInterface<mixed>|null $submitted
     */
    private function show(string $tab, ?HostSettings $host, ?FormInterface $submitted = null): Response
    {
        $status = null === $submitted ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY;
        if (null === $host) {
            return $this->render('admin/host/index.html.twig', ['host' => null, 'tabs' => self::TABS, 'tab' => $tab, 'forms' => [], 'backups' => [], 'secrets' => []]);
        }

        $forms = [];
        foreach (array_keys(self::TABS) as $key) {
            // The certificates, SSH keys and drift tabs have no Symfony form (the keys page posts to SshKeyController).
            if (\in_array($key, ['certificates', 'ssh', 'drift'], true)) {
                continue;
            }
            $forms[$key] = ($key === $tab && null !== $submitted ? $submitted : $this->form($key, $host))->createView();
        }

        return $this->render('admin/host/index.html.twig', [
            'host' => $host,
            'tabs' => self::TABS,
            'tab' => $tab,
            'forms' => $forms,
            'backups' => 'backups' === $tab ? $this->backupRows() : [],
            // An identity saved before the rules were tightened (FLE-59) fails its next save: say so up front.
            'gitStoredInvalid' => 'git' === $tab && null === $submitted && [] !== GitIdentityRule::violations($host->gitUserName(), $host->gitUserEmail()),
            'workspace' => $this->workspace->run()->toArray(),
            // FLE-298: a Docker socket that is not root:docker 0660 is warned about on every tab.
            'dockerSocket' => $this->dockerSocket->check(),
            'tls' => 'certificates' === $tab ? $this->tlsStatus->read() : null,
            'mirror' => 'network' === $tab ? $this->mirrorView() : null,
            'capacity' => 'capacity' === $tab ? $this->capacity->report() : null,
            'sshKeys' => 'ssh' === $tab ? $this->sshCatalog->entries() : [],
            'gitHosts' => 'ssh' === $tab ? $this->gitHosts() : [],
            'unsliced' => 'capacity' === $tab ? $this->unsliced->list() : [],
            'provision' => 'hardening' === $tab ? [
                'unavailable' => $this->provision->unavailable(),
                'active' => $this->provision->activeJob(),
                'latest' => $this->provision->latestJob(),
                'firewall' => $this->firewall->state(),
                'firesAt' => $this->firewall->firesAt(),
                'confirmCommand' => FirewallSwitch::COMMAND,
                'recentAuth' => $this->reauth->requireRecentAuth(),
            ] : null,
            'drift' => 'drift' === $tab ? [
                'unavailable' => $this->drift->unavailable(),
                'active' => $this->drift->activeJob(),
                'latest' => $this->drift->latestJob(),
                'report' => $this->drift->lastReport(),
            ] : null,
            'secrets' => [
                'claude' => $this->rotation->lastRotated(),
                'smtp' => $this->secrets->fleetWideUpdatedAt(HostSecrets::SMTP_PASSWORD),
            ],
        ], new Response('', $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function hardeningData(): array
    {
        $current = $this->hostVars->hardening();

        return [
            'enabled' => $current->enabled,
            'sshUsers' => implode("\n", $current->sshAllowUsers),
            'invokingUser' => $current->invokingUser,
            'trustedIps' => implode("\n", $current->trustedIps),
            'graceMinutes' => $current->graceMinutes,
            'hardenTmp' => $current->hardenTmp,
        ];
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim($value ?? '');

        return '' === $value ? null : $value;
    }

    /**
     * @return array{ports: list<int>, skipped: list<string>}
     */
    private function mirrorView(): array
    {
        $plan = $this->portMirror->plan();

        return ['ports' => $plan->publicPorts(), 'skipped' => $plan->skipped];
    }

    /**
     * @return list<array{name: string, modifiedAt: \DateTimeImmutable, size: string}>
     */
    private function backupRows(): array
    {
        return array_map(
            static fn (BackupInfo $info): array => ['name' => $info->name, 'modifiedAt' => $info->modifiedAt, 'size' => HostHealthReport::bytes($info->size)],
            $this->backups->list(BackupKind::Fleet),
        );
    }

    private function notInitialised(): Response
    {
        return $this->show('general', null)->setStatusCode(Response::HTTP_CONFLICT);
    }

    private function back(string $tab): Response
    {
        return $this->redirectToRoute('settings', ['tab' => $tab], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function text(FormInterface $form, string $field): ?string
    {
        $value = $form->get($field)->getData();

        return \is_string($value) ? $value : null;
    }

    /**
     * A plain (non-Symfony-form) POST must carry the stateless CSRF token: the hidden `_token` field, accepted only
     * for a same-origin request (Sec-Fetch-Site / Origin).
     */
    private function csrfValid(Request $request): bool
    {
        $token = $request->request->get('_token');

        return \is_string($token) && $this->isCsrfTokenValid('submit', $token);
    }

    /**
     * The name the change is recorded under in the secret store (1 to 64 bytes).
     */
    private function actor(): string
    {
        $name = mb_strcut($this->getUser()?->getUserIdentifier() ?? '', 0, 64, 'UTF-8');

        return '' === $name ? 'admin' : $name;
    }

    /**
     * @return list<string> the git hosts of the registered projects' remotes (FLE-271: the push-key check takes no free-form host)
     */
    private function gitHosts(): array
    {
        $hosts = [];
        foreach ($this->projectRepository->all() as $project) {
            try {
                $hosts[] = \App\Fleet\Ssh\PushKeyCheck::hostFromRemote($project->git());
            } catch (\InvalidArgumentException) {
            }
        }

        return array_values(array_unique($hosts));
    }
}
