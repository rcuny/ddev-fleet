<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * The only password hash format the fleet stores and hands to Authelia: PHP's password_hash(PASSWORD_ARGON2ID)
 * output, `$argon2id$v=19$m=<kib>,t=<passes>,p=<lanes>$<salt>$<digest>` (unpadded base64). Anything else (bcrypt,
 * a plaintext password, a YAML-shaped string) is refused when it enters a User.
 */
final class PasswordHash
{
    private const string PATTERN = '/^\$argon2id\$v=19\$m=\d{1,9},t=\d{1,4},p=\d{1,3}\$[A-Za-z0-9+\/]{8,128}\$[A-Za-z0-9+\/]{16,255}$/D';
    private const int MAX_LENGTH = 255;

    private function __construct()
    {
    }

    public static function isArgon2id(#[\SensitiveParameter] string $hash): bool
    {
        return \strlen($hash) <= self::MAX_LENGTH && 1 === preg_match(self::PATTERN, $hash);
    }

    public static function assertArgon2id(#[\SensitiveParameter] string $hash): void
    {
        if (!self::isArgon2id($hash)) {
            // Never echo the value: whatever was passed may be a plaintext password.
            throw new \InvalidArgumentException('The password hash is not an argon2id hash.');
        }
    }
}
