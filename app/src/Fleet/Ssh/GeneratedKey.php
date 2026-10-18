<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * A freshly generated key pair. The private half is the only copy in memory: it is hidden from var_dump and refuses
 * serialisation so that it cannot leak through a log line, a dump or a cache.
 */
final readonly class GeneratedKey
{
    /**
     * @param string $privatePem  OpenSSH `openssh-key-v1` private key, PEM armoured, unencrypted
     * @param string $publicLine  `ssh-ed25519 <base64> <comment>`
     * @param string $fingerprint `SHA256:` + unpadded base64 of the sha256 of the public blob
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $privatePem,
        public string $publicLine,
        public string $fingerprint,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['privatePem' => '*redacted*', 'publicLine' => $this->publicLine, 'fingerprint' => $this->fingerprint];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A generated private key must not be serialised.');
    }
}
