<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * The scan tool is missing or cannot start, as opposed to a host that simply offers no key.
 */
final class KnownHostScannerUnavailable extends \RuntimeException
{
}
