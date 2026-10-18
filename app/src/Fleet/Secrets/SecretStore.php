<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

use App\Domain\Secret\Secret;
use App\Domain\Secret\SecretRepository;
use Psr\Clock\ClockInterface;

/**
 * Minimal fleet-wide secret storage (OVH credentials, Claude token). Values are sealed by SecretBox; the database
 * only ever sees ciphertext. Project scope, reveal, audit and rotation arrive with M0.05.
 */
final readonly class SecretStore
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/D';

    /** Fleet-scope secrets carry an empty scope id (see Secret). */
    private const string FLEET_SCOPE_ID = '';

    public function __construct(
        private SecretRepository $secrets,
        private SecretBox $box,
        private ClockInterface $clock,
    ) {
    }

    public function putFleetWide(string $name, string $plaintext, string $by): void
    {
        self::assertName($name);
        if ('' === $by || \strlen($by) > 64) {
            throw new \InvalidArgumentException('The updater must be 1 to 64 bytes.');
        }

        $sealed = $this->box->seal($plaintext);
        $now = $this->clock->now();
        $existing = $this->secrets->find(Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID, $name);
        if (null === $existing) {
            $this->secrets->save(new Secret(
                Secret::SCOPE_FLEET,
                self::FLEET_SCOPE_ID,
                $name,
                $sealed->ciphertext,
                $sealed->nonce,
                $sealed->keyVersion,
                $by,
                $now,
            ));

            return;
        }

        $existing->replace($sealed->ciphertext, $sealed->nonce, $sealed->keyVersion, $by, $now);
        $this->secrets->save($existing);
    }

    /**
     * @throws SecretOpenFailed when the stored value cannot be authenticated
     */
    public function getFleetWide(string $name): ?string
    {
        self::assertName($name);
        $secret = $this->secrets->find(Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID, $name);
        if (null === $secret) {
            return null;
        }

        try {
            return $this->box->open(new SealedSecret($secret->ciphertext(), $secret->nonce(), $secret->keyVersion()));
        } catch (SecretOpenFailed $e) {
            throw new SecretOpenFailed(\sprintf('The stored secret "%s" could not be opened.', $name), 0, $e);
        }
    }

    public function hasFleetWide(string $name): bool
    {
        self::assertName($name);

        return null !== $this->secrets->find(Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID, $name);
    }

    public function forgetFleetWide(string $name): void
    {
        self::assertName($name);
        $secret = $this->secrets->find(Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID, $name);
        if (null !== $secret) {
            $this->secrets->remove($secret);
        }
    }

    private static function assertName(string $name): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('A secret name must match [a-z][a-z0-9_]{1,63}.');
        }
    }
}
