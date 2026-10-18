<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * One validated known_hosts line: `[@revoked ]<host> <type> <key>`. Construction is the validation point, so a value
 * of this class can be written to the file without further checks: the host matches a strict pattern (no whitespace,
 * commas, wildcards or leading dash), the type is allow-listed and the key is canonical base64 of a matching blob.
 */
final readonly class KnownHostEntry
{
    /** A host name or address, or `[host]:port` for a non-standard port. */
    public const string HOST_PATTERN = '/^(\[[A-Za-z0-9][A-Za-z0-9.-]{0,252}\]:\d{1,5}|[A-Za-z0-9][A-Za-z0-9.-]{0,252})$/D';

    /** Lower case: OpenSSH matches host names case-insensitively, so "GitHub.com" must not be a second identity. */
    public string $host;

    public function __construct(
        string $host,
        public string $type,
        public string $key,
        public bool $revoked = false,
    ) {
        $this->host = self::normalizeHost($host);
        SshPublicKeys::blob($type, $key);
    }

    /**
     * @throws \InvalidArgumentException when $host is not a valid known_hosts host
     */
    public static function normalizeHost(string $host): string
    {
        if (1 !== preg_match(self::HOST_PATTERN, $host)) {
            throw new \InvalidArgumentException('Not a valid SSH host name.');
        }
        if (str_starts_with($host, '[') && (int) substr($host, (int) strrpos($host, ':') + 1) > 65535) {
            throw new \InvalidArgumentException('Not a valid SSH host name.');
        }

        return strtolower($host);
    }

    public function fingerprint(): string
    {
        return SshPublicKeys::fingerprint($this->type, $this->key);
    }

    public function line(): string
    {
        return ($this->revoked ? '@revoked ' : '').$this->host.' '.$this->type.' '.$this->key;
    }

    public function sameKeyAs(self $other): bool
    {
        return $this->host === $other->host && $this->type === $other->type && $this->key === $other->key;
    }
}
