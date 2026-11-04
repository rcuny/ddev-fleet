<?php

declare(strict_types=1);

namespace App\Fleet\Tls;

/**
 * How healthy the host's certificates are. The value is the `fleet host:tls-status` exit code.
 */
enum TlsLevel: int
{
    case Ok = 0;
    case Warning = 1;
    case Error = 2;

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Warning => 'warning',
            self::Error => 'error',
        };
    }
}
