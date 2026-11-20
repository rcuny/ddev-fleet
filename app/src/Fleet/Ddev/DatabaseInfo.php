<?php

declare(strict_types=1);

namespace App\Fleet\Ddev;

/**
 * The database account of a DDEV project, from `ddev describe -j` (`raw.dbinfo`): DDEV's own db/db unless the project
 * overrides it. The password is a secret: it is never logged and never put on a command line.
 */
final readonly class DatabaseInfo
{
    public function __construct(
        public string $type = 'mariadb',
        public string $host = 'db',
        public int $port = 0,
        public string $name = 'db',
        public string $user = 'db',
        #[\SensitiveParameter]
        public string $password = 'db',
    ) {
    }

    public function isPostgres(): bool
    {
        return str_starts_with($this->type, 'postgres');
    }

    /** DDEV's default account, for a project whose description cannot be read. */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Reads `ddev describe -j` output; null when it is not a project description with a usable account (the values go
     * into a shell script, so only plain identifiers are accepted for the names).
     */
    public static function fromDescribe(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $info = \is_array($data) && \is_array($data['raw'] ?? null) ? ($data['raw']['dbinfo'] ?? null) : null;
        if (!\is_array($info)) {
            return null;
        }
        $type = \is_string($info['database_type'] ?? null) ? $info['database_type'] : 'mariadb';
        $host = \is_string($info['host'] ?? null) && '' !== $info['host'] ? $info['host'] : 'db';
        $name = \is_string($info['dbname'] ?? null) ? $info['dbname'] : '';
        $user = \is_string($info['username'] ?? null) ? $info['username'] : '';
        $password = \is_string($info['password'] ?? null) ? $info['password'] : '';
        $port = $info['dbPort'] ?? 0;
        $port = \is_string($port) || \is_int($port) ? (int) $port : 0;
        $plain = '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/';
        foreach ([$type, $host, $name, $user] as $value) {
            if (1 !== preg_match($plain, $value)) {
                return null;
            }
        }
        if ($port < 0 || $port > 65535) {
            return null;
        }

        return new self($type, $host, $port, $name, $user, $password);
    }
}
