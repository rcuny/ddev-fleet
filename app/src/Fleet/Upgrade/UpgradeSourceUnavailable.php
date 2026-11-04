<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** The release source (GitHub or the override) could not be read: offline, timed out, refused, or oversized. */
final class UpgradeSourceUnavailable extends \RuntimeException
{
}
