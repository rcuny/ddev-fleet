<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * The root helper refused, failed or timed out. The message is safe to show: it carries a redacted output tail only.
 */
final class PrivilegedApplyFailed extends \RuntimeException
{
}
