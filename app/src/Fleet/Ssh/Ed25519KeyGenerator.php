<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * Generates ed25519 SSH key pairs in-process (libsodium), so no process is started during an HTTP request.
 *
 * The private key is written in the OpenSSH `openssh-key-v1` container format (PROTOCOL.key): cipher and KDF `none`,
 * one key, two equal random check integers, padding 1, 2, 3... to the 8-byte block size.
 */
final readonly class Ed25519KeyGenerator
{
    private const string TYPE = 'ssh-ed25519';

    public function generate(string $comment): GeneratedKey
    {
        if (\strlen($comment) > 255 || 1 === preg_match('/[\x00-\x1f\x7f]/', $comment)) {
            throw new \InvalidArgumentException('The key comment must be at most 255 characters and contain no control characters.');
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair); // 32-byte seed followed by the 32-byte public key
        $public = sodium_crypto_sign_publickey($pair);
        sodium_memzero($pair);

        try {
            $blob = self::string(self::TYPE).self::string($public);
            $check = random_bytes(4);
            $private = $check.$check.self::string(self::TYPE).self::string($public).self::string($secret).self::string($comment);
            for ($pad = 1; 0 !== \strlen($private) % 8; ++$pad) {
                $private .= \chr($pad);
            }

            $container = "openssh-key-v1\0"
                .self::string('none').self::string('none').self::string('')
                .pack('N', 1).self::string($blob).self::string($private);
            sodium_memzero($private);
        } finally {
            sodium_memzero($secret);
        }

        $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\n"
            .chunk_split(base64_encode($container), 70, "\n")
            ."-----END OPENSSH PRIVATE KEY-----\n";
        sodium_memzero($container);

        return new GeneratedKey(
            $pem,
            self::TYPE.' '.base64_encode($blob).('' === $comment ? '' : ' '.$comment),
            SshPublicKeys::fingerprintOfBlob($blob),
        );
    }

    private static function string(string $value): string
    {
        return pack('N', \strlen($value)).$value;
    }
}
