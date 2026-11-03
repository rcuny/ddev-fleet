<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

/**
 * Replaces every registered secret value with MASK. The longest value is replaced first, so a secret that contains
 * another one is hidden whole. It is the application's one LogMasker and SecretRegistry.
 */
final class RegisteredSecretsMasker implements TailAwareLogMasker, SecretRegistry
{
    public const string MASK = '[masked]';

    /** @var array<string, true> */
    private array $secrets = [];

    /** @var list<string>|null the secrets, longest first; null when the set changed since the last mask() */
    private ?array $ordered = null;

    public function register(#[\SensitiveParameter] string $value): void
    {
        if ('' === $value || isset($this->secrets[$value])) {
            return;
        }
        $this->secrets[$value] = true;
        $this->ordered = null;
    }

    public function clear(): void
    {
        $this->secrets = [];
        $this->ordered = null;
    }

    public function mask(string $text): string
    {
        if ([] === $this->secrets) {
            return $text;
        }
        $this->ordered ??= $this->sorted();

        return str_replace($this->ordered, self::MASK, $text);
    }

    public function longestSecret(): int
    {
        $longest = 0;
        foreach ($this->secrets as $value => $_) {
            $longest = max($longest, \strlen((string) $value));
        }

        return $longest;
    }

    /**
     * @return list<string>
     */
    private function sorted(): array
    {
        $values = array_map(strval(...), array_keys($this->secrets));
        usort($values, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $values;
    }
}
