<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * Reads the public key out of an unencrypted `openssh-key-v1` private key without starting a process, so the
 * `.pub` next to a private key can be checked on every run. Other formats (PKCS#1, PKCS#8, encrypted) give null and
 * are left to `ssh-keygen -y`.
 */
final class OpensshPrivateKey
{
    private const string MAGIC = "openssh-key-v1\0";

    private function __construct()
    {
    }

    /**
     * @return array{type: string, key: string}|null
     */
    public static function publicKey(#[\SensitiveParameter] string $pem): ?array
    {
        if (1 !== preg_match('/^-----BEGIN OPENSSH PRIVATE KEY-----\n([A-Za-z0-9+\/=\n]+)-----END OPENSSH PRIVATE KEY-----\n?$/D', str_replace("\r\n", "\n", $pem), $m)) {
            return null;
        }
        $raw = base64_decode(str_replace("\n", '', $m[1]), true);
        if (false === $raw || !str_starts_with($raw, self::MAGIC)) {
            return null;
        }

        $offset = \strlen(self::MAGIC);
        $cipher = self::field($raw, $offset);
        $kdf = self::field($raw, $offset);
        self::field($raw, $offset); // kdf options
        if ('none' !== $cipher || 'none' !== $kdf || 1 !== self::uint($raw, $offset)) {
            return null;
        }
        $blob = self::field($raw, $offset);
        if (null === $blob) {
            return null;
        }
        $blobOffset = 0;
        $type = self::field($blob, $blobOffset);
        if (null === $type) {
            return null;
        }
        $key = base64_encode($blob);
        try {
            SshPublicKeys::blob($type, $key);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return ['type' => $type, 'key' => $key];
    }

    private static function uint(string $data, int &$offset): ?int
    {
        if (\strlen($data) < $offset + 4) {
            return null;
        }
        /** @var array{1: int} $value */
        $value = unpack('N', $data, $offset);
        $offset += 4;

        return $value[1];
    }

    private static function field(string $data, int &$offset): ?string
    {
        $length = self::uint($data, $offset);
        if (null === $length || \strlen($data) < $offset + $length) {
            return null;
        }
        $value = substr($data, $offset, $length);
        $offset += $length;

        return $value;
    }
}
