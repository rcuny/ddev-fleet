<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * What checking a delivery's signature found. `Unreadable` is the host's problem (the stored secret cannot be opened:
 * the secret key was lost or replaced), not the sender's, and is logged as such.
 */
enum SignatureVerdict: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unreadable = 'unreadable';
}
