<?php

declare(strict_types=1);

namespace App\Fleet\Host\Drift;

/** No drift report came back (FLE-261). The message is safe to show. */
final class DriftCheckFailed extends \RuntimeException
{
}
