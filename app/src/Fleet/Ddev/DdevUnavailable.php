<?php

declare(strict_types=1);

namespace App\Fleet\Ddev;

/**
 * `ddev list` did not answer in time, failed, or printed something that is not a project list.
 */
final class DdevUnavailable extends \RuntimeException
{
}
