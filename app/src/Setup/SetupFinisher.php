<?php

declare(strict_types=1);

namespace App\Setup;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Domain\Ssh\SshKeyRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\CaddyReloader;
use App\Fleet\System\ConfigValue;
use App\Fleet\System\GateMode;
use App\Fleet\System\PrivilegedApply;
use App\Fleet\System\SystemRenderer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Completes setup: puts the sign-in gate in front of the app. Every step is idempotent and setup is marked complete
 * last, so a failure anywhere leaves it open with the same token and a second Finish simply runs again.
 *
 * Order (pre-flight ruling D6): users file, `apply authelia`, gate flipped to Authelia (which reloads Caddy), then,
 * in DNS mode only, `apply caddy` (the helper restarts Caddy a few seconds later, so nothing may need Caddy after
 * it), then the completion mark. If `apply caddy` fails after the gate flipped, the setup gate is put back, so the
 * wizard stays reachable for the retry.
 *
 * Time: the steps wait on external processes. A step is not started unless its worst case still fits in BUDGET_SECONDS
 * (the gate restore after a failed Caddy apply comes on top, see REQUIRED_REQUEST_TIMEOUT), and the php-fpm pool must
 * allow REQUIRED_REQUEST_TIMEOUT seconds per request or the pool kills Finish half way.
 */
final readonly class SetupFinisher
{
    /** Seconds from the first side effect to the last one. */
    public const float BUDGET_SECONDS = 150.0;

    /** Seconds the gate restore after a failed `apply caddy` can take (Caddy validate + reload). Not part of the budget. */
    public const float RESTORE_SECONDS = CaddyReloader::MAX_SECONDS;

    /**
     * The php-fpm `request_terminate_timeout` (and any proxy read timeout) the Finish request needs. The worst case is
     * `apply caddy` starting as late as the budget allows and failing at its limit, followed by the gate restore:
     * BUDGET_SECONDS + RESTORE_SECONDS = 210 s, plus 30 s to render the response. It is also at least the sum of every
     * step's own timeout (apply x2, validate+reload x2 = 240 s), so no change in step order can exceed it; the unit
     * test SetupFinisherTimeoutsTest pins both. The installer's pool must set at least this.
     */
    public const int REQUIRED_REQUEST_TIMEOUT = 240;

    public function __construct(
        private HostSettingsRepository $settings,
        private UserRepository $users,
        private SshKeyRepository $keys,
        private AutheliaUsersFile $usersFile,
        private SystemRenderer $system,
        private LockFactory $locks,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $by the signed-in setup session's name for the log (the admin's username)
     *
     * @throws SetupRefused when setup cannot finish (complete already, a precondition missing, another Finish running,
     *                      out of time); nothing was marked complete. Any other exception comes from a step; it is
     *                      logged first, never with a secret, and setup stays open.
     */
    public function finish(string $by): FinishResult
    {
        if ('' === $by || \strlen($by) > 64) {
            throw new \InvalidArgumentException('The requester must be 1 to 64 bytes.');
        }

        // What this caller saw before waiting for the lock; compared with the row once the lock is held.
        $seenTokenHash = $this->settings->get()?->setupTokenHash();

        $lock = $this->locks->createLock('setup-finish', self::REQUIRED_REQUEST_TIMEOUT, false);
        if (!$lock->acquire(false)) {
            throw new SetupRefused('Finish is already running; wait for it to end.');
        }

        try {
            return $this->run($by, $seenTokenHash);
        } finally {
            $lock->release();
        }
    }

    private function run(string $by, ?string $seenTokenHash): FinishResult
    {
        // Re-read under the lock: the entity may have been loaded before another Finish completed (identity map).
        $host = $this->settings->fresh() ?? throw new SetupRefused('The host is not initialised yet; run fleet:host:init first.');
        if ($host->isSetupComplete()) {
            throw new SetupRefused('Setup is already completed.');
        }
        if ($host->setupTokenHash() !== $seenTokenHash) {
            throw new SetupRefused('The setup token changed while Finish was waiting; start again from the new link.');
        }
        $this->assertCanFinish($host);

        $started = $this->seconds();
        $steps = [];
        $step = 'preparing';
        $completedAt = $this->clock->now();

        try {
            $step = 'users';
            $this->usersFile->sync($this->users);
            $steps[] = $step;

            $step = 'authelia';
            $this->within($started, PrivilegedApply::TIMEOUT, $step);
            $this->system->stageAndApply(ApplyTarget::Authelia, $host);
            $steps[] = $step;

            $step = 'gate';
            $this->within($started, CaddyReloader::MAX_SECONDS, $step);
            $this->system->renderGate($host, GateMode::Authelia);
            $steps[] = $step;

            if (TlsMode::OvhDns === $host->tlsMode()) {
                $step = 'caddy';
                $this->applyCaddy($host, $started);
                $steps[] = $step;
            }

            $step = 'complete';
            $completedAt = $this->clock->now();
            // One flush: the token is gone in the same write that marks setup complete.
            $host->clearSetupToken();
            $host->markSetupComplete($completedAt);
            $this->settings->save($host);
            $steps[] = $step;
        } catch (\Throwable $e) {
            $this->logger->error('Setup could not finish.', ['step' => $step, 'by' => $by, 'exception' => $e::class, 'message' => $e->getMessage()]);

            throw $e;
        }

        $this->logger->info('Setup finished.', ['by' => $by, 'steps' => $steps]);

        return new FinishResult($completedAt, $host->domain(), $host->tlsMode(), $steps);
    }

    private function applyCaddy(HostSettings $host, float $started): void
    {
        try {
            $this->within($started, PrivilegedApply::TIMEOUT, 'caddy');
            $this->system->stageAndApply(ApplyTarget::Caddy, $host);
        } catch (\Throwable $e) {
            // The gate already requires a sign-in; put the wizard's gate back so the retry can be reached.
            try {
                $this->system->renderGate($host, GateMode::Setup);
            } catch (\Throwable $restore) {
                $this->logger->error('The setup gate could not be put back after a failed Caddy apply.', ['exception' => $restore::class, 'message' => $restore->getMessage()]);
            }

            throw $e;
        }
    }

    /**
     * @throws SetupRefused
     */
    private function assertCanFinish(HostSettings $host): void
    {
        if ($host->isSetupComplete()) {
            throw new SetupRefused('Setup is already complete.');
        }
        if (0 === $this->users->countAdmins()) {
            throw new SetupRefused('Create the administrator first.');
        }
        foreach (SshKeyRole::cases() as $role) {
            if (null === $this->keys->findByRole($role)) {
                throw new SetupRefused(\sprintf('The %s SSH key does not exist yet; generate the SSH keys first.', $role->value));
            }
        }
        // Every value that ends up in a root-installed file is checked now, before the first side effect.
        try {
            ConfigValue::domain($host->domain());
            ConfigValue::acmeEmail($host->acmeEmail());
            $this->system->assertRenderable($host);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new SetupRefused('The settings cannot be applied: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SetupRefused when $worstCase more seconds would break the budget
     */
    private function within(float $started, float $worstCase, string $step): void
    {
        if ($this->seconds() - $started + $worstCase > self::BUDGET_SECONDS) {
            throw new SetupRefused(\sprintf('Finish ran out of time before "%s"; nothing was marked complete, press Finish again.', $step));
        }
    }

    private function seconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
