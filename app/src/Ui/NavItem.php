<?php

declare(strict_types=1);

namespace App\Ui;

/**
 * One destination of the app shell (sidebar, phone bottom bar and More sheet).
 */
final readonly class NavItem
{
    /**
     * @param string      $key       stable identifier, also the route name (except the design system page)
     * @param string      $role      the role needed to see and open the page: ROLE_USER or ROLE_ADMIN
     * @param string|null $milestone the roadmap milestone that fills the placeholder, null for real pages
     * @param bool        $primary   true when the item sits in the phone bottom bar instead of the More sheet
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $routeName,
        public string $path,
        public string $icon,
        public string $role,
        public ?string $milestone,
        public bool $primary,
    ) {
    }

    /**
     * Whether $requestPath is this item's page or below it (Home only matches itself).
     */
    public function isCurrent(string $requestPath): bool
    {
        if ('/' === $this->path) {
            return '/' === $requestPath;
        }

        return $requestPath === $this->path || str_starts_with($requestPath, $this->path.'/');
    }
}
