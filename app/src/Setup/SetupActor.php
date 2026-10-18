<?php

declare(strict_types=1);

namespace App\Setup;

use App\Domain\User\UserRepository;

/**
 * The name the wizard's changes are recorded under (secret store, SSH key audit, setup log): the administrator the
 * wizard created, or "setup" before there is one. Always 1 to 64 bytes, as those APIs require.
 */
final readonly class SetupActor
{
    public const string FALLBACK = 'setup';

    public function __construct(private UserRepository $users)
    {
    }

    public function name(): string
    {
        foreach ($this->users->all() as $user) {
            if ($user->isAdmin() && $user->isEnabled()) {
                return $user->username();
            }
        }

        return self::FALLBACK;
    }
}
