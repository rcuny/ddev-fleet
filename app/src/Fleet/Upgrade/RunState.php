<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** Where UpgradeRunner::prepare() ends: ready to upgrade, nothing to do, refused (exit 3), usage (2) or failed (1). */
enum RunState: string
{
    case Ready = 'ready';
    case UpToDate = 'up_to_date';
    case Refused = 'refused';
    case Usage = 'usage';
    case Failed = 'failed';
}
