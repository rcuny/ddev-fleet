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
            new NavItem('instances', 'Instances', 'instances', '/instances', 'lucide:server', self::USER, 'M0.04', true),
            new NavItem('projects', 'Projects', 'projects', '/admin/projects', 'lucide:folder', self::ADMIN, null, false),
            new NavItem('jobs', 'Jobs', 'jobs', '/admin/jobs', 'lucide:activity', self::ADMIN, 'M0.04', true),
            new NavItem('webhooks', 'Webhooks', 'webhooks', '/admin/webhooks', 'lucide:webhook', self::ADMIN, null, false),
            new NavItem('schedules', 'Schedules', 'schedules', '/admin/schedules', 'lucide:calendar-clock', self::ADMIN, 'M0.09', false),
            new NavItem('secrets', 'Secrets', 'secrets', '/admin/secrets', 'lucide:key-round', self::ADMIN, 'M0.05', false),
            new NavItem('users', 'Users', 'users', '/admin/users', 'lucide:users', self::ADMIN, null, false),
            new NavItem('settings', 'Settings', 'settings', '/admin/host', 'lucide:settings', self::ADMIN, null, false),
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
