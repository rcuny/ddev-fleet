<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Ui\NavigationCatalog;
use App\Ui\NavItem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The desktop sidebar menu: every destination the current user may open.
 */
#[AsTwigComponent]
final class MainNav
{
    public function __construct(
        private readonly NavigationCatalog $catalog,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @return list<array{item: NavItem, current: bool}>
     */
    public function items(): array
    {
        $path = $this->requests->getMainRequest()?->getPathInfo() ?? '/';

        return array_map(
            static fn (NavItem $item): array => ['item' => $item, 'current' => $item->isCurrent($path)],
            $this->catalog->forCurrentUser(),
        );
    }
}
