<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * A freshly generated key pair. The private half is a private property behind an explicit accessor, is hidden from
 * var_dump/print_r (__debugInfo) and json_encode (JsonSerializable) and refuses serialisation, so it does not leak
 * through a log line, a dump or a cache by accident. (var_export and reflection-based dumpers can still read any
 * private property: do not hand a GeneratedKey to them.).
 */
final readonly class GeneratedKey implements \JsonSerializable
{
    /**
     * @param string $privatePem  OpenSSH `openssh-key-v1` private key, PEM armoured, unencrypted
     * @param string $publicLine  `ssh-ed25519 <base64> <comment>`
     * @param string $fingerprint `SHA256:` + unpadded base64 of the sha256 of the public blob
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $privatePem,
        public string $publicLine,
        public string $fingerprint,
    ) {
    }

    /**
     * The OpenSSH private key; callers write it to disk and must not keep, log or return it.
     */
    public function privatePem(): string
    {
        return $this->privatePem;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['privatePem' => '*redacted*', 'publicLine' => $this->publicLine, 'fingerprint' => $this->fingerprint];
    }

    /**
     * @return array{publicLine: string, fingerprint: string}
     */
    public function jsonSerialize(): array
    {
        return ['publicLine' => $this->publicLine, 'fingerprint' => $this->fingerprint];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A generated private key must not be serialised.');
    }
}
