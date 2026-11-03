<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

use App\Domain\Secret\Secret;
use App\Domain\Secret\SecretRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Secret storage. Values are sealed by SecretBox; the database only ever sees ciphertext.
 *
 * Two kinds of names share the table. The host's own secrets (OVH credentials, Claude token; the "fleet-wide" methods)
 * have lowercase names and are written by the setup wizard. The secrets an administrator manages in the browser or on
 * the command line (the "managed" methods) have UPPER_CASE names, live in the fleet scope or in one project's scope
 * and are what `[[token]]` placeholders resolve to; the host's own secrets never are.
 */
final readonly class SecretStore
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/D';

    /** Names of managed secrets. */
    public const string MANAGED_NAME_PATTERN = '/^[A-Z][A-Z0-9_]{0,63}$/D';
    public const int MAX_VALUE_BYTES = 65536;

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
        $this->putManyFleetWide([$name => $plaintext], $by);
    }

    /**
     * Stores several fleet-wide secrets in ONE transaction: either all of them are written or none is (a failure
     * midway leaves the previous values). Everything is validated and sealed before the first write.
     *
     * @param array<string, string> $values secret name => plaintext
     *
     * @throws SecretConflict when a concurrent writer created the same secret first
     */
    public function putManyFleetWide(#[\SensitiveParameter] array $values, string $by): void
    {
        if ('' === $by || \strlen($by) > 64) {
            throw new \InvalidArgumentException('The updater must be 1 to 64 bytes.');
        }
        $sealedValues = [];
        foreach ($values as $name => $plaintext) {
            $name = (string) $name;
            self::assertName($name);
            $sealedValues[$name] = $this->box->seal($plaintext, self::fleetContext($name));
        }
        if ([] === $sealedValues) {
            return;
        }

        $this->upsert(Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID, $sealedValues, $by);
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

    /**
     * When a fleet-wide secret was last written, or null when it is not set. Never opens (decrypts) the value, so a
     * status display cannot leak it.
     */
    public function fleetWideUpdatedAt(string $name): ?\DateTimeImmutable
    {
        self::assertName($name);

        return $this->secrets->find(Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID, $name)?->updatedAt();
    }

    public function forgetFleetWide(string $name): void
    {
        self::assertName($name);
        $secret = $this->secrets->find(Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID, $name);
        if (null !== $secret) {
            $this->secrets->remove($secret);
        }
    }

    /**
     * Stores a managed secret in the fleet scope ($project null) or in the scope of $project.
     *
     * @throws \InvalidArgumentException for a bad name, an empty or oversized value or a bad updater
     * @throws SecretConflict            when a concurrent writer created the same secret first
     */
    public function putManaged(?string $project, string $name, #[\SensitiveParameter] string $plaintext, string $by): void
    {
        self::assertManagedName($name);
        if ('' === $plaintext || \strlen($plaintext) > self::MAX_VALUE_BYTES) {
            throw new \InvalidArgumentException(\sprintf('A secret value must be 1 to %d bytes.', self::MAX_VALUE_BYTES));
        }
        if ('' === $by || \strlen($by) > 64) {
            throw new \InvalidArgumentException('The updater must be 1 to 64 bytes.');
        }
        [$scope, $scopeId] = self::managedScope($project);

        $this->upsert($scope, $scopeId, [$name => $this->box->seal($plaintext, self::context($scope, $scopeId, $name))], $by);
    }

    /**
     * @throws SecretOpenFailed when the stored value cannot be authenticated
     */
    public function getManaged(?string $project, string $name): ?string
    {
        self::assertManagedName($name);
        [$scope, $scopeId] = self::managedScope($project);
        $secret = $this->secrets->find($scope, $scopeId, $name);

        return null === $secret ? null : $this->open($secret);
    }

    public function hasManaged(?string $project, string $name): bool
    {
        self::assertManagedName($name);
        [$scope, $scopeId] = self::managedScope($project);

        return null !== $this->secrets->find($scope, $scopeId, $name);
    }

    /**
     * @return bool whether a secret was removed
     */
    public function forgetManaged(?string $project, string $name): bool
    {
        self::assertManagedName($name);
        [$scope, $scopeId] = self::managedScope($project);
        $secret = $this->secrets->find($scope, $scopeId, $name);
        if (null === $secret) {
            return false;
        }
        $this->secrets->remove($secret);

        return true;
    }

    /**
     * Names and update stamps of the managed secrets of one scope; never a value.
     *
     * @return list<SecretSummary> ordered by name
     */
    public function listManaged(?string $project): array
    {
        [$scope, $scopeId] = self::managedScope($project);

        return $this->summaries($scope, $scopeId, static fn (string $name): bool => 1 === preg_match(self::MANAGED_NAME_PATTERN, $name));
    }

    /**
     * Names and update stamps of the host's own secrets (the setup wizard's); never a value.
     *
     * @return list<SecretSummary> ordered by name
     */
    public function listHost(): array
    {
        return $this->summaries(Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID, static fn (string $name): bool => 1 === preg_match(self::NAME_PATTERN, $name));
    }

    /**
     * Removes every secret of a project (the project is being deleted).
     *
     * @return list<string> the names removed
     */
    public function forgetAllOfProject(string $project): array
    {
        $names = [];
        foreach ($this->secrets->listFor(Secret::SCOPE_PROJECT, $project) as $secret) {
            $names[] = $secret->name();
            $this->secrets->remove($secret);
        }

        return $names;
    }

    /**
     * @param array<string, SealedSecret> $sealedValues name => sealed value
     *
     * @throws SecretConflict when a concurrent writer created the same secret first
     */
    private function upsert(string $scope, string $scopeId, array $sealedValues, string $by): void
    {
        $now = $this->clock->now();
        try {
            $this->secrets->transactional(function () use ($scope, $scopeId, $sealedValues, $by, $now): void {
                foreach ($sealedValues as $name => $sealed) {
                    $existing = $this->secrets->find($scope, $scopeId, $name);
                    if (null === $existing) {
                        $this->secrets->save(new Secret(
                            $scope,
                            $scopeId,
                            $name,
                            $sealed->ciphertext,
                            $sealed->nonce,
                            $sealed->keyVersion,
                            $by,
                            $now,
                        ));
                        continue;
                    }

                    $existing->replace($sealed->ciphertext, $sealed->nonce, $sealed->keyVersion, $by, $now);
                    $this->secrets->save($existing);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new SecretConflict(\sprintf('A secret of "%s" was stored concurrently; retry.', implode('", "', array_keys($sealedValues))), 0, $e);
        }
    }

    /**
     * @param \Closure(string): bool $wanted
     *
     * @return list<SecretSummary>
     */
    private function summaries(string $scope, string $scopeId, \Closure $wanted): array
    {
        $summaries = [];
        foreach ($this->secrets->listFor($scope, $scopeId) as $secret) {
            if ($wanted($secret->name())) {
                $summaries[] = new SecretSummary($secret->name(), $secret->scope(), $secret->scopeId(), $secret->updatedBy(), $secret->updatedAt());
            }
        }

        return $summaries;
    }

    private function open(Secret $secret): string
    {
        try {
            return $this->box->open(
                new SealedSecret($secret->ciphertext(), $secret->nonce(), $secret->keyVersion()),
                self::context($secret->scope(), $secret->scopeId(), $secret->name()),
            );
        } catch (SecretOpenFailed $e) {
            throw new SecretOpenFailed(\sprintf('The stored secret "%s" could not be opened.', $secret->name()), 0, $e);
        }
    }

    /**
     * @return array{string, string} scope and scope id
     */
    private static function managedScope(?string $project): array
    {
        return null === $project ? [Secret::SCOPE_FLEET, self::FLEET_SCOPE_ID] : [Secret::SCOPE_PROJECT, $project];
    }

    private static function assertManagedName(string $name): void
    {
        if (1 !== preg_match(self::MANAGED_NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('A secret name must match [A-Z][A-Z0-9_]{0,63}.');
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
