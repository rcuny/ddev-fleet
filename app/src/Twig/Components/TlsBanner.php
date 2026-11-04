<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Fleet\Tls\TlsStatus;
use App\Fleet\Tls\TlsStatusFile;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The shell banner "Let's Encrypt is refusing certificates" (red) or "certificate trouble" (amber), for
 * administrators only: the status file is never even read for anyone else.
 */
#[AsTwigComponent]
final class TlsBanner
{
    public function __construct(
        private readonly TlsStatusFile $file,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @return TlsStatus|null null when no banner is due: the status is healthy or missing, or the user is no administrator
     */
    public function notice(): ?TlsStatus
    {
        if (!$this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return null;
        }
        $status = $this->file->read();

        return null === $status || $status->isHealthy() ? null : $status;
    }
}
