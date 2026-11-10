<?php

declare(strict_types=1);

namespace App\Domain\Ssh;

/**
 * How a Fleet-managed extra SSH key came to be (FLE-266): generated on the host, or imported from an existing private key.
 */
enum ManagedSshKeySource: string
{
    case Generated = 'generated';
    case Imported = 'imported';
}
