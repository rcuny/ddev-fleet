<?php

declare(strict_types=1);

namespace App\Fleet\Export;

/**
 * Seals a small JSON document with a key derived from an operator passphrase: libsodium `crypto_pwhash` (Argon2id) and
 * `crypto_secretbox`. The salt, the cost parameters and the nonce travel with the ciphertext, so the file opens on any
 * host that knows the passphrase. The host's own secret key is not involved: the file must open on another host.
 */
final readonly class PassphraseSealer
{
    public const string KDF = 'argon2id13';
    public const int MIN_OPS = 2;
    public const int MAX_OPS = 10;
    public const int MIN_MEM = 64 * 1024 * 1024;
    public const int MAX_MEM = 1024 * 1024 * 1024;
    public const int MIN_PASSPHRASE = 8;
    public const int MAX_PASSPHRASE = 1024;

    public function __construct(
        private int $opslimit = \SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        private int $memlimit = \SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
    ) {
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array{kdf: string, opslimit: int, memlimit: int, salt: string, nonce: string, ciphertext: string}
     */
    public function seal(array $payload, #[\SensitiveParameter] string $passphrase): array
    {
        if (null !== $error = self::passphraseError($passphrase)) {
            throw InvalidExport::of($error);
        }
        $salt = random_bytes(\SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->key($passphrase, $salt, $this->opslimit, $this->memlimit);

        try {
            $ciphertext = sodium_crypto_secretbox(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        return [
            'kdf' => self::KDF,
            'opslimit' => $this->opslimit,
            'memlimit' => $this->memlimit,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /**
     * @param array{kdf: string, opslimit: int, memlimit: int, salt: string, nonce: string, ciphertext: string} $sealed a parsed, validated block
     *
     * @return array<mixed>
     *
     * @throws InvalidExport with one fixed message for a wrong passphrase and for damaged data alike
     */
    public function open(array $sealed, #[\SensitiveParameter] string $passphrase): array
    {
        $salt = base64_decode($sealed['salt'], true);
        $nonce = base64_decode($sealed['nonce'], true);
        $ciphertext = base64_decode($sealed['ciphertext'], true);
        if (false === $salt || false === $nonce || false === $ciphertext
            || \SODIUM_CRYPTO_PWHASH_SALTBYTES !== \strlen($salt) || \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== \strlen($nonce)) {
            throw InvalidExport::of('secrets: the sealed block is damaged.');
        }

        $key = $this->key($passphrase, $salt, $sealed['opslimit'], $sealed['memlimit']);
        try {
            $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }
        if (false === $plain) {
            throw InvalidExport::of('The passphrase is wrong, or the sealed secrets are damaged.');
        }

        try {
            $payload = json_decode($plain, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidExport::of('The passphrase is wrong, or the sealed secrets are damaged.');
        } finally {
            sodium_memzero($plain);
        }

        return \is_array($payload) ? $payload : throw InvalidExport::of('The passphrase is wrong, or the sealed secrets are damaged.');
    }

    public static function passphraseError(#[\SensitiveParameter] string $passphrase): ?string
    {
        $length = \strlen($passphrase);
        if ($length < self::MIN_PASSPHRASE) {
            return \sprintf('The passphrase needs at least %d characters.', self::MIN_PASSPHRASE);
        }
        if ($length > self::MAX_PASSPHRASE) {
            return \sprintf('The passphrase is limited to %d bytes.', self::MAX_PASSPHRASE);
        }

        return null;
    }

    private function key(#[\SensitiveParameter] string $passphrase, string $salt, int $opslimit, int $memlimit): string
    {
        try {
            return sodium_crypto_pwhash(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $opslimit, $memlimit, \SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        } catch (\SodiumException) {
            throw InvalidExport::of('secrets: the sealed block cannot be opened on this host (key derivation failed).');
        }
    }
}
