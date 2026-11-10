<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * Which engine installed this host: `bash` (the numbered steps) or `ansible` (the playbook). Both engines write the
 * word to /etc/ddev-fleet/installed-engine at the end of a run; a host without the file, or with anything else in it,
 * was installed before the record existed and is `bash`. `fleet upgrade` runs the same engine again.
 */
final readonly class InstalledEngine
{
    public const string BASH = 'bash';
    public const string ANSIBLE = 'ansible';

    public function __construct(private string $engineFile)
    {
    }

    public function name(): string
    {
        $text = is_readable($this->engineFile) ? trim((string) file_get_contents($this->engineFile)) : '';

        return self::ANSIBLE === $text ? self::ANSIBLE : self::BASH;
    }
}
