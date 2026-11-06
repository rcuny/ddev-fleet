<?php

declare(strict_types=1);

namespace App\Security\Reauth;

use App\Domain\User\UserRepository;
use App\Fleet\Audit\AuditLog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Checks the signed-in user's password against the fleet user store (argon2id) and, on success, opens the re-auth
 * window. Attempts are rate limited per user (limiter `reauth`); success, failure and refusal are audited without the
 * password.
 */
final readonly class ReauthService
{
    public function __construct(
        private UserRepository $users,
        private ReauthWindow $window,
        private AuditLog $audit,
        #[Autowire(service: 'limiter.reauth')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    /**
     * @return bool whether the password is right (the window is open then)
     *
     * @throws ReauthThrottled when $actor failed too often lately; nothing is checked then
     */
    public function confirm(string $actor, #[\SensitiveParameter] string $password): bool
    {
        $limit = $this->limiter->create($actor)->consume();
        if (!$limit->isAccepted()) {
            $this->audit->record($actor, AuditLog::REAUTH_FAILURE, 'throttled');

            throw new ReauthThrottled(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        $user = $this->users->findByUsername($actor);
        // Always one argon2id verification, so a missing account does not answer faster than a wrong password.
        $hash = null !== $user && '' !== $user->passwordHash() ? $user->passwordHash() : self::decoyHash();
        $valid = password_verify($password, $hash) && null !== $user && $user->isEnabled();

        if (!$valid) {
            $this->audit->record($actor, AuditLog::REAUTH_FAILURE, 'password');

            return false;
        }

        $this->window->open();
        $this->audit->record($actor, AuditLog::REAUTH_SUCCESS, 'password');

        return true;
    }

    private static function decoyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash(bin2hex(random_bytes(8)), \PASSWORD_ARGON2ID);
    }
}
