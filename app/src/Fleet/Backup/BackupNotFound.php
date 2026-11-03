<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

/**
 * The name is not a backup file name this service writes, or no such backup exists.
 */
final class BackupNotFound extends \RuntimeException
{
}
