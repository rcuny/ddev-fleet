<?php

declare(strict_types=1);

namespace App\Twig;

use App\Fleet\Upgrade\UpgradeStatus;
use App\Fleet\Upgrade\UpgradeStatusReader;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `upgrade_status()` for the shell: the installed version and whether a newer release is out, for administrators
 * only (anyone else gets null and nothing is read). It reads the cache, never the network, and once per request.
 */
final class UpgradeNoticeExtension extends AbstractExtension
{
    private ?UpgradeStatus $status = null;

    public function __construct(
        private readonly UpgradeStatusReader $reader,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('upgrade_status', $this->status(...))];
    }

    public function status(): ?UpgradeStatus
    {
        if (!$this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return null;
        }

        return $this->status ??= $this->reader->read();
    }
}
