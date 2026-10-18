<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

use App\Domain\Secret\Secret;
use App\Domain\Secret\SecretRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

    /**
     * The context a sealed value is bound to: scope, scope id and name, each length-prefixed so no two different
     * triples can produce the same bytes (a plain "a|b|c" join could be shifted across the separators).
     */
    public static function context(string $scope, string $scopeId, string $name): string
    {
        $context = '';
        foreach ([$scope, $scopeId, $name] as $part) {
            $context .= pack('N', \strlen($part)).$part;
        }

        return $context;
    }

    /**
     * @throws SecretConflict when a concurrent writer created the same secret first
     */
    public function putFleetWide(string $name, #[\SensitiveParameter] string $plaintext, string $by): void
    {
        self::assertName($name);
        if ('' === $by || \strlen($by) > 64) {
            throw new \InvalidArgumentException('The updater must be 1 to 64 bytes.');
        }

        $sealed = $this->box->seal($plaintext, self::fleetContext($name));
        $now = $this->clock->now();
        try {
            $this->secrets->transactional(function () use ($name, $sealed, $by, $now): void {
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
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new SecretConflict(\sprintf('The secret "%s" was stored concurrently; retry.', $name), 0, $e);
        }
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
            return $this->box->open(
                new SealedSecret($secret->ciphertext(), $secret->nonce(), $secret->keyVersion()),
                self::fleetContext($name),
            );
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

    private static function fleetContext(string $name): string
    {
        return self::context(Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID, $name);
    }

    private static function assertName(string $name): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('A secret name must match [a-z][a-z0-9_]{1,63}.');
        }
    }
}
