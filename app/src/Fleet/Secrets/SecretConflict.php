<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * Two writers stored the same secret at the same moment and this one lost the unique constraint. Nothing was
 * written; the entity manager is closed after the failed transaction, so the caller should fail the request and
 * let the user retry.
 */
final class SecretConflict extends \RuntimeException
{
}
