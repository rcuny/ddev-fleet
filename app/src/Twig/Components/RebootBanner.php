<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Fleet\Host\RebootSignal;
use App\Fleet\Host\RebootStatus;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The shell banner "this host needs a reboot", for administrators only: the flag is never even read for anyone else.
 */
#[AsTwigComponent]
final class RebootBanner
{
    public function __construct(
        private readonly RebootSignal $signal,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @return RebootStatus|null null when no banner is due: no reboot is pending, or the user is not an administrator
     */
    public function notice(): ?RebootStatus
    {
        if (!$this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return null;
        }
        $status = $this->signal->status();

        return $status->required ? $status : null;
    }
}
