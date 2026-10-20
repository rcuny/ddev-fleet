<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

/**
 * The outcome of BackupVerifier: ok means the integrity check passed and the schema is not newer than this release.
 */
final readonly class BackupVerification
{
    /**
     * @param string|null  $schema   current / behind / newer, null when the file has no schema to compare (a queue backup)
     * @param list<string> $pending  migrations this release ships and the file has not run
     * @param list<string> $problems
     */
    public function __construct(
        public string $path,
        public bool $integrityOk,
        public ?string $schema,
        public array $pending,
        public array $problems,
    ) {
    }

    public function ok(): bool
    {
        return [] === $this->problems;
    }
}
