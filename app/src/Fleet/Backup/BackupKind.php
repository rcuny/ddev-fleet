<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

/**
 * Which database a backup file holds; the value is the file name prefix.
 */
enum BackupKind: string
{
    case Fleet = 'fleet';
    case Queue = 'queue';
}
