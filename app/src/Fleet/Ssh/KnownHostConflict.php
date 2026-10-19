<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * Refusal to trust a key for a host that already has a different one. Replacing a host key is an explicit act.
 */
final class KnownHostConflict extends \RuntimeException
{
}
