<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * The name a signed-in user's changes are recorded under (secret store, audit log): their user name, at most 64 bytes.
 */
final readonly class CurrentActor
{
    public const string UNKNOWN = 'unknown';

    public function __construct(private Security $security)
    {
    }

    public function name(): string
    {
        $user = $this->security->getUser();
        $name = null === $user ? '' : mb_strcut($user->getUserIdentifier(), 0, 64);

        return '' === $name ? self::UNKNOWN : $name;
    }
}
