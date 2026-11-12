<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

/**
 * An automatic repair found that the guard would refuse the instance and went no further (FLE-317): nothing was
 * stopped or restarted. Not a failure of the job that ran the repair.
 */
final class RepairSkipped extends \RuntimeException
{
}
