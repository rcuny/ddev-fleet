<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Security\FleetUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Who is signed in: display name, role and groups as asserted by the authenticating proxy.
 */
#[AsTwigComponent]
final class UserMenu
{
    /** 'menu' is the top bar dropdown, 'block' the static identity block. */
    public string $variant = 'menu';

    public function __construct(private readonly Security $security)
    {
    }

    public function user(): ?FleetUser
    {
        $user = $this->security->getUser();

        return $user instanceof FleetUser ? $user : null;
    }

    public function name(): string
    {
        return $this->user()?->displayName() ?? 'Signed out';
    }

    public function email(): ?string
    {
        $email = $this->user()?->email();

        return null !== $email && '' !== $email ? $email : null;
    }

    public function initial(): string
    {
        return mb_strtoupper(mb_substr($this->name(), 0, 1));
    }

    public function role(): string
    {
        return $this->security->isGranted('ROLE_ADMIN') ? 'Administrator' : 'Member';
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        return $this->user()?->groups() ?? [];
    }
}
