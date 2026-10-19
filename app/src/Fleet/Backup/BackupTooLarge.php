<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

/**
 * The databases are too large to be copied inside a web request; the backup timer or `fleet fleet:backup:create` is
 * the way to back them up.
 */
final class BackupTooLarge extends \RuntimeException
{
    public function __construct(public readonly int $size, public readonly int $limit)
    {
        parent::__construct(\sprintf('The databases are %d bytes, above the %d bytes a web request may back up.', $size, $limit));
    }
}
