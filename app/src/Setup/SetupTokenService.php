<?php

declare(strict_types=1);

namespace App\Setup;

use App\Domain\Host\HostSettingsRepository;
use Psr\Clock\ClockInterface;

/**
 * The one-time token that opens the setup wizard. 32 random bytes (43 base64url characters), shown once on the host
 * shell (fleet:setup:token), valid for 24 hours, replaced by the next mint, consumed when setup finishes. Only its
 * sha256 digest is stored, so a copy of the database does not open the wizard.
 */
final readonly class SetupTokenService
{
    public const int TTL_SECONDS = 86400;
    public const int TOKEN_LENGTH = 43;

    /** Anything longer than this cannot be a token we issued; it is not even hashed. */
    private const int MAX_CANDIDATE_LENGTH = 128;

    public function __construct(
        private HostSettingsRepository $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Mints a token and returns it; this is the only time it exists in clear. Any previous token (and every cookie
     * issued from it) stops working.
     *
     * @throws SetupRefused when the host is not initialised or setup is already complete
     */
    public function mint(): string
    {
        $host = $this->settings->get() ?? throw new SetupRefused('The host is not initialised yet; run fleet:host:init first.');
        if ($host->isSetupComplete()) {
            throw new SetupRefused('Setup is already complete; run fleet:setup:reset to open it again.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $host->storeSetupToken(self::digest($token), $this->clock->now()->add(new \DateInterval('PT'.self::TTL_SECONDS.'S')));
        $this->settings->save($host);

        return $token;
    }

    /**
     * True only for the current, unexpired token while setup is incomplete. The comparison is constant-time, and a
     * missing token costs the same work as a wrong one.
     */
    public function verify(#[\SensitiveParameter] string $token): bool
    {
        if ('' === $token || \strlen($token) > self::MAX_CANDIDATE_LENGTH) {
            return false;
        }
        $candidate = self::digest($token);

        $host = $this->settings->get();
        $stored = $host?->setupTokenHash();
        $expires = $host?->setupTokenExpiresAt();

        // Compared even when there is nothing to compare with, so a missing token costs the same as a wrong one.
        $matches = hash_equals($stored ?? str_repeat('0', 64), $candidate);

        return $matches && null !== $stored && null !== $expires && !$host->isSetupComplete() && $this->clock->now() < $expires;
    }

    /**
     * Forgets the token (and so every cookie issued from it). No token is valid afterwards.
     */
    public function invalidate(): void
    {
        $host = $this->settings->get();
        if (null === $host || null === $host->setupTokenHash()) {
            return;
        }
        $host->clearSetupToken();
        $this->settings->save($host);
    }

    private static function digest(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
