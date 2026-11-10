<?php

declare(strict_types=1);

namespace App\Fleet\Host\Drift;

/** The playbook run printed no usable drift report (FLE-261). The message is safe to show. */
final class DriftReportInvalid extends \RuntimeException
{
}
