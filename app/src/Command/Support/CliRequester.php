<?php

declare(strict_types=1);

namespace App\Command\Support;

/**
 * Who a CLI request is recorded as: `cli:<user>`, the person behind sudo when there is one.
 */
final class CliRequester
{
    public static function name(): string
    {
        $user = getenv('SUDO_USER');
        $user = \is_string($user) && '' !== $user ? $user : get_current_user();
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $user) ?? '';

        return 'cli:'.('' === $clean ? 'unknown' : substr($clean, 0, 32));
    }
}
