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
        private AdminGroup $admins,
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
            $user = new User($username, $displayName, $email, $hash, [$this->admins->name()]);
        } else {
            $user->updateProfile($displayName, $email);
            $user->changePasswordHash($hash);
            $user->changeGroups($this->admins->addedTo($user->groups()));
            $user->setEnabled(true);
        }
        $this->users->save($user);

        return $user;
    }

    /**
     * Creates a user (an administrator when $admin; the admin group is the only group a user can be put in here).
     *
     * @throws \InvalidArgumentException when the username is taken or the username, display name, email or password is
     *                                   not acceptable; nothing is stored then
     */
    public function create(string $username, string $displayName, string $email, #[\SensitiveParameter] string $plainPassword, bool $admin = false): User
    {
        $this->assertUsername($username);
        $this->assertProfile($displayName, $email);
        $this->assertPassword($plainPassword, $username);
        if (null !== $this->users->findByUsername($username)) {
            throw new InvalidUserInput('username', 'A user with this username already exists.');
        }

        $user = new User($username, $displayName, $email, self::hash($plainPassword), $admin ? [$this->admins->name()] : []);
        $this->users->save($user);

        return $user;
    }

    /**
     * Changes the display name and/or the email; null keeps the current value.
     *
     * @throws UnknownUser
     * @throws \InvalidArgumentException when a new value is not acceptable; nothing is changed then
     */
    public function updateProfile(string $username, ?string $displayName, ?string $email): User
    {
        $user = $this->users->findByUsername($username) ?? throw UnknownUser::named($username);
        $displayName ??= $user->displayName();
        $email ??= $user->email();
        $this->assertProfile($displayName, $email);

        $user->updateProfile($displayName, $email);
        $this->users->save($user);

        return $user;
    }

    /**
     * Enables or disables the user (a disabled user is left out of users.yml and cannot sign in). Callers that can
     * remove the last administrator check LastAdminGuard first.
     *
     * @throws UnknownUser
     */
    public function setEnabled(string $username, bool $enabled): User
    {
        $user = $this->users->findByUsername($username) ?? throw UnknownUser::named($username);
        $user->setEnabled($enabled);
        $this->users->save($user);

        return $user;
    }

    /**
     * Adds the user to the admin group or takes it out of it (other groups are kept). Callers that can remove the
     * last administrator check LastAdminGuard first.
     *
     * @throws UnknownUser
     */
    public function setAdmin(string $username, bool $admin): User
    {
        $user = $this->users->findByUsername($username) ?? throw UnknownUser::named($username);
        $user->changeGroups($admin
            ? $this->admins->addedTo($user->groups())
            : array_values(array_filter($user->groups(), fn (string $group): bool => $group !== $this->admins->name())));
        $this->users->save($user);

        return $user;
    }

    /**
     * Deletes the user and its project memberships. Callers check LastAdminGuard first.
     *
     * @throws UnknownUser
     */
    public function delete(string $username): void
    {
        $this->users->remove($this->users->findByUsername($username) ?? throw UnknownUser::named($username));
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
            throw new InvalidUserInput('username', 'The username must be 1-64 characters: lowercase letters, digits, dot, underscore or dash, starting with a letter or digit.');
        }
    }

    private function assertProfile(string $displayName, string $email): void
    {
        if ('' === trim($displayName) || mb_strlen($displayName, 'UTF-8') > self::DISPLAY_NAME_MAX
            || !mb_check_encoding($displayName, 'UTF-8') || 1 === preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $displayName)) {
            throw new InvalidUserInput('displayName', 'The display name must be 1-255 characters without control characters.');
        }
        if (\strlen($email) > self::EMAIL_MAX || 1 === preg_match('/\s/', $email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidUserInput('email', 'The email address is not valid.');
        }
    }

    private function assertPassword(#[\SensitiveParameter] string $plain, string $username): void
    {
        $violations = $this->policy->violations($plain, $username);
        if ([] !== $violations) {
            throw new InvalidUserInput('password', implode(' ', $violations));
        }
    }

    private static function hash(#[\SensitiveParameter] string $plain): string
    {
        return password_hash($plain, \PASSWORD_ARGON2ID);
    }
}
