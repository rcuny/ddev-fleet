<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

final class UnknownUser extends \RuntimeException
{
    public static function named(string $username): self
    {
        return new self(\sprintf('There is no user "%s".', addcslashes($username, "\0..\37")));
    }
}
