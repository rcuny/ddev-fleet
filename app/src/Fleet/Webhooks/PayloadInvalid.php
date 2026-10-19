<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * The body is authentic but not something the receiver can use. The message is safe to send back (no input echoed).
 */
final class PayloadInvalid extends \DomainException
{
}
