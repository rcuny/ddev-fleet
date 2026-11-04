<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

/** How a pre-flight finding weighs: Block stops the upgrade, Warn needs the operator's eyes, Info is context. */
enum Severity: string
{
    case Block = 'block';
    case Warn = 'warn';
    case Info = 'info';
}
