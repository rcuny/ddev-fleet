<?php

declare(strict_types=1);

namespace App\Setup;

use App\Domain\Host\HostSettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The session of a person who proved they hold the setup token: `<expiry>.<hmac>`, where the hmac covers the stored
 * token digest and the expiry. The key is a labelled sub-key of APP_SECRET, so the same secret never signs two kinds
 * of value. Because the digest is part of the signed data, minting a new token, finishing setup or resetting it makes
 * every cookie issued before invalid without keeping a list of them.
 *
 * `__Host-` means the browser only accepts it Secure, for the exact host, path `/`, and without a Domain attribute.
 */
final readonly class SetupCookie
{
    public const string NAME = '__Host-fleet_setup';
    public const int LIFETIME_SECONDS = 7200;

    private const string KEY_LABEL = 'fleet/setup-cookie/v1';
    private const int MIN_SECRET_LENGTH = 16;
    private const string FORMAT = '/^(?<exp>[0-9]{1,12})\.(?<mac>[0-9a-f]{64})$/D';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private string $appSecret,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SetupRefused when the host has no live token to base a cookie on, or APP_SECRET is unusable
     */
    public function issue(HostSettings $host): Cookie
    {
        $hash = $host->setupTokenHash();
        $tokenExpiry = $host->setupTokenExpiresAt();
        $now = $this->clock->now();
        if ($host->isSetupComplete() || null === $hash || null === $tokenExpiry || $now >= $tokenExpiry) {
            throw new SetupRefused('There is no valid setup token to start a session from.');
        }

        // Never outlives the token it came from.
        $expiry = min($now->getTimestamp() + self::LIFETIME_SECONDS, $tokenExpiry->getTimestamp());

        return Cookie::create(
            self::NAME,
            $expiry.'.'.$this->mac($hash, $expiry),
            $expiry,
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    /**
     * False for a missing, malformed, tampered or expired value, and whenever the host's token changed or setup
     * completed since it was issued.
     */
    public function isValid(#[\SensitiveParameter] ?string $value, HostSettings $host): bool
    {
        $hash = $host->setupTokenHash();
        $tokenExpiry = $host->setupTokenExpiresAt();
        if (null === $value || null === $hash || null === $tokenExpiry || $host->isSetupComplete()
            || \strlen($this->appSecret) < self::MIN_SECRET_LENGTH
            || 1 !== preg_match(self::FORMAT, $value, $m)) {
            return false;
        }
        $expiry = (int) $m['exp'];
        $now = $this->clock->now()->getTimestamp();
        if ($expiry <= $now || $now >= $tokenExpiry->getTimestamp()) {
            return false;
        }

        return hash_equals($this->mac($hash, $expiry), $m['mac']);
    }

    private function mac(string $tokenHash, int $expiry): string
    {
        if (\strlen($this->appSecret) < self::MIN_SECRET_LENGTH) {
            throw new SetupRefused('APP_SECRET is missing or too short to sign the setup session.');
        }
        $key = hash_hmac('sha256', self::KEY_LABEL, $this->appSecret, true);

        return hash_hmac('sha256', $tokenHash.'|'.$expiry, $key);
    }
}
