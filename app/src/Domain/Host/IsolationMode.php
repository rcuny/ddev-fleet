<?php

declare(strict_types=1);

namespace App\Domain\Host;

/**
 * FLE-299: what instance network isolation does on this host. `off` installs nothing (the table does not exist),
 * `report` records every flow between instances and the host without cutting any, `enforce` drops what no declared link allows.
 */
enum IsolationMode: string
{
    case Off = 'off';
    case Report = 'report';
    case Enforce = 'enforce';
}
