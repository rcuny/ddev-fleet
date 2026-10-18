<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\User\User;
use App\Domain\User\UserRepository;

/**
 * Creates and updates fleet users. Passwords are hashed with argon2id using PHP's default cost (m=64 MiB, t=4, p=1),
 * which Authelia accepts as is; the plaintext is never stored, logged or returned.
 */
final readonly class UserService
{
    private const int DISPLAY_NAME_MAX = 255;
    private const int EMAIL_MAX = 254;

    public function __construct(
        private UserRepository $users,
        private PasswordPolicy $policy,
    ) {
    }

    /**
     * Creates the administrator, or, when the username exists, updates its profile and password, puts it in the admin
     * group (other groups are kept) and enables it.
     *
     * @throws \InvalidArgumentException when the username, display name, email or password is not acceptable; nothing
     *                                   is stored then
     */
    public function upsertAdmin(string $username, string $displayName, string $email, #[\SensitiveParameter] string $plainPassword): User
    {
        // Everything is checked before anything is created or touched, so a refusal never leaves a half-updated user.
        $this->assertUsername($username);
        $this->assertProfile($displayName, $email);
        $this->assertPassword($plainPassword, $username);
        $hash = self::hash($plainPassword);

        $user = $this->users->findByUsername($username);
        if (null === $user) {
            $user = new User($username, $displayName, $email, $hash, [User::ADMIN_GROUP]);
        } else {
            $user->updateProfile($displayName, $email);
            $user->changePasswordHash($hash);
            $user->changeGroups([...$user->groups(), User::ADMIN_GROUP]);
            $user->setEnabled(true);
        }
        $this->users->save($user);

        return $user;
    }

    /**
     * @throws UnknownUser
     * @throws \InvalidArgumentException when the password breaks the policy; the stored hash is unchanged then
     */
    public function setPassword(string $username, #[\SensitiveParameter] string $plain): User
    {
        $user = $this->users->findByUsername($username) ?? throw UnknownUser::named($username);
        $this->assertPassword($plain, $username);

        $user->changePasswordHash(self::hash($plain));
        $this->users->save($user);

        return $user;
    }

    private function assertUsername(string $username): void
    {
        if (1 !== preg_match(User::USERNAME_PATTERN, $username)) {
            throw new \InvalidArgumentException('The username must be 1-64 characters: lowercase letters, digits, dot, underscore or dash, starting with a letter or digit.');
        }
    }

    private function assertProfile(string $displayName, string $email): void
    {
        if ('' === trim($displayName) || mb_strlen($displayName, 'UTF-8') > self::DISPLAY_NAME_MAX
            || !mb_check_encoding($displayName, 'UTF-8') || 1 === preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $displayName)) {
            throw new \InvalidArgumentException('The display name must be 1-255 characters without control characters.');
        }
        if (\strlen($email) > self::EMAIL_MAX || 1 === preg_match('/\s/', $email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('The email address is not valid.');
        }
    }

    private function assertPassword(#[\SensitiveParameter] string $plain, string $username): void
    {
        $violations = $this->policy->violations($plain, $username);
        if ([] !== $violations) {
            throw new \InvalidArgumentException(implode(' ', $violations));
        }
    }

    private static function hash(#[\SensitiveParameter] string $plain): string
    {
        return password_hash($plain, \PASSWORD_ARGON2ID);
    }
}
