<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * Validation and fingerprints for SSH public keys in the `<type> <base64>` form used by authorized_keys and
 * known_hosts. Everything that ends up in a file under FLEET_HOME/ssh passes through here first.
 */
final class SshPublicKeys
{
    /** Key types the fleet accepts (what GitHub, Bitbucket, GitLab and `ssh-keyscan -t ed25519,ecdsa,rsa` produce). */
    public const array TYPES = [
        'ssh-ed25519',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'ssh-rsa',
    ];

    /** An RSA-8192 public blob is about 1.1 KiB; this leaves ample room and still bounds what is written to disk. */
    private const int MAX_BASE64_LENGTH = 8192;

    private function __construct()
    {
    }

    /**
     * Returns the decoded key blob.
     *
     * @throws \InvalidArgumentException unless $type is allow-listed and $base64 is canonical base64 of a blob that
     *                                   starts with that same type string
     */
    public static function blob(string $type, string $base64): string
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported SSH key type.');
        }
        if ('' === $base64 || \strlen($base64) > self::MAX_BASE64_LENGTH || 1 !== preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $base64)) {
            throw new \InvalidArgumentException('The SSH key is not valid base64.');
        }
        $blob = base64_decode($base64, true);
        if (false === $blob || base64_encode($blob) !== $base64) {
            throw new \InvalidArgumentException('The SSH key is not valid base64.');
        }
        $typeLength = \strlen($type);
        if (\strlen($blob) <= 4 + $typeLength || pack('N', $typeLength).$type !== substr($blob, 0, 4 + $typeLength)) {
            throw new \InvalidArgumentException('The SSH key blob does not match its declared type.');
        }

        return $blob;
    }

    /**
     * `SHA256:` plus the unpadded base64 of the sha256 of the blob: what `ssh-keygen -l` prints.
     */
    public static function fingerprint(string $type, string $base64): string
    {
        return self::fingerprintOfBlob(self::blob($type, $base64));
    }

    public static function fingerprintOfBlob(string $blob): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Parses `<type> <base64> [comment]` (the first line only) into its validated parts. The comment must not
     * contain control characters.
     *
     * @return array{type: string, key: string, comment: string}
     *
     * @throws \InvalidArgumentException
     */
    public static function parseLine(string $line): array
    {
        $line = trim($line);
        if (1 !== preg_match('/^(\S+) (\S+)(?: ([^\x00-\x1f\x7f]*))?$/D', $line, $m)) {
            throw new \InvalidArgumentException('Not an SSH public key line.');
        }
        self::blob($m[1], $m[2]);

        return ['type' => $m[1], 'key' => $m[2], 'comment' => trim($m[3] ?? '')];
    }
}
