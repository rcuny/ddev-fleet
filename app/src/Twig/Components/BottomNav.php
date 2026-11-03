<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Ui\NavigationCatalog;
use App\Ui\NavItem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The phone bottom bar: the primary destinations plus a More sheet with the rest.
 */
#[AsTwigComponent]
final class BottomNav
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
        return $this->decorate(true);
    }

    /**
     * Destinations that do not fit in the bar, for the More sheet.
     *
     * @return list<array{item: NavItem, current: bool}>
     */
    public function more(): array
    {
        return $this->decorate(false);
    }

    public function moreIsCurrent(): bool
    {
        foreach ($this->more() as $entry) {
            if ($entry['current']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{item: NavItem, current: bool}>
     */
    private function decorate(bool $primary): array
    {
        $path = $this->requests->getMainRequest()?->getPathInfo() ?? '/';
        $entries = [];
        foreach ($this->catalog->forCurrentUser() as $item) {
            if ($item->primary === $primary) {
                $entries[] = ['item' => $item, 'current' => $item->isCurrent($path)];
            }
        }

        return $entries;
    }
}
