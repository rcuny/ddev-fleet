<?php

declare(strict_types=1);

namespace App\Ui;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The single list of shell destinations: the sidebar, the phone bottom bar, the More sheet and the
 * placeholder pages are all rendered from it. Access itself is enforced by security.yaml; the role
 * here only decides what the menu shows.
 */
final readonly class NavigationCatalog
{
    private const string USER = 'ROLE_USER';
    private const string ADMIN = 'ROLE_ADMIN';

    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    /**
     * @return list<NavItem>
     */
    public function all(): array
    {
        return [
            new NavItem('home', 'Home', 'home', '/', 'lucide:house', self::USER, null, true),
            new NavItem('instances', 'Instances', 'instances', '/instances', 'lucide:server', self::USER, null, true),
            new NavItem('projects', 'Projects', 'projects', '/admin/projects', 'lucide:folder', self::ADMIN, null, false),
            new NavItem('jobs', 'Jobs', 'jobs', '/admin/jobs', 'lucide:activity', self::ADMIN, null, true),
            new NavItem('webhooks', 'Webhooks', 'webhooks', '/admin/webhooks', 'lucide:webhook', self::ADMIN, null, false),
            new NavItem('schedules', 'Schedules', 'schedules', '/admin/schedules', 'lucide:calendar-clock', self::ADMIN, null, false),
            new NavItem('secrets', 'Secrets', 'secrets', '/admin/secrets', 'lucide:key-round', self::ADMIN, null, false),
            new NavItem('api_tokens', 'API tokens', 'api_tokens', '/admin/api-tokens', 'lucide:lock', self::ADMIN, null, false),
            new NavItem('users', 'Users', 'users', '/admin/users', 'lucide:users', self::ADMIN, null, false),
            new NavItem('settings', 'Settings', 'settings', '/admin/host', 'lucide:settings', self::ADMIN, null, false),
            new NavItem('upgrade', 'Upgrade', 'upgrade', '/admin/host/upgrade', 'lucide:circle-arrow-up', self::ADMIN, null, false),
            new NavItem('design-system', 'Design system', 'admin_design_system', '/admin/design-system', 'lucide:palette', self::ADMIN, null, false),
        ];
    }

    /**
     * @return list<NavItem>
     */
    public function forCurrentUser(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (NavItem $item): bool => $this->authorizationChecker->isGranted($item->role),
        ));
    }

    /**
     * Whether $item is the destination the request path belongs to. The most specific destination wins, so the
     * Upgrade page (below /admin/host) does not light up Settings as well.
     */
    public function isCurrent(NavItem $item, string $requestPath): bool
    {
        if (!$item->isCurrent($requestPath)) {
            return false;
        }
        foreach ($this->all() as $other) {
            if ($other->key !== $item->key && \strlen($other->path) > \strlen($item->path) && $other->isCurrent($requestPath)) {
                return false;
            }
        }

        return true;
    }

    public function find(string $key): ?NavItem
    {
        foreach ($this->all() as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }

        return null;
    }
}
