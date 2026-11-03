<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

/**
 * A user field was refused. The message is safe to show (it never contains the password); `field` is the form field it
 * belongs to: username, displayName, email or password.
 */
final class InvalidUserInput extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
