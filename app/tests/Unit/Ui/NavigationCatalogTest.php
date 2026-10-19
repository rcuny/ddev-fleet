<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui;

use App\Ui\NavigationCatalog;
use App\Ui\NavItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class NavigationCatalogTest extends TestCase
{
    private const array KEYS = ['home', 'instances', 'projects', 'jobs', 'webhooks', 'schedules', 'secrets', 'users', 'settings', 'design-system'];

    public function testKeysAreInTheDocumentedOrder(): void
    {
        self::assertSame(self::KEYS, array_map(static fn (NavItem $i): string => $i->key, $this->catalog()->all()));
    }

    public function testLabelsAndPaths(): void
    {
        $byKey = $this->byKey();

        self::assertSame('Home', $byKey['home']->label);
        self::assertSame('/', $byKey['home']->path);
        self::assertSame('Design system', $byKey['design-system']->label);
        self::assertSame('/admin/design-system', $byKey['design-system']->path);
        self::assertSame('/instances', $byKey['instances']->path);
        self::assertSame('/admin/projects', $byKey['projects']->path);
    }

    public function testEveryRoleIsUserOrAdmin(): void
    {
        foreach ($this->catalog()->all() as $item) {
            self::assertContains($item->role, ['ROLE_USER', 'ROLE_ADMIN'], $item->key);
        }
        $byKey = $this->byKey();
        self::assertSame('ROLE_USER', $byKey['home']->role);
        self::assertSame('ROLE_USER', $byKey['instances']->role);
        foreach (array_diff(self::KEYS, ['home', 'instances']) as $key) {
            self::assertSame('ROLE_ADMIN', $byKey[$key]->role, $key);
        }
    }

    public function testEveryAdminItemLivesUnderAdmin(): void
    {
        // access_control ^/admin is what actually protects these pages; the menu only hides them.
        foreach ($this->catalog()->all() as $item) {
            if ('ROLE_ADMIN' === $item->role) {
                self::assertStringStartsWith('/admin/', $item->path, $item->key);
            }
        }
    }

    public function testExactlyHomeInstancesJobsArePrimary(): void
    {
        $primary = array_values(array_map(
            static fn (NavItem $i): string => $i->key,
            array_filter($this->catalog()->all(), static fn (NavItem $i): bool => $i->primary),
        ));

        self::assertSame(['home', 'instances', 'jobs'], $primary);
    }

    public function testPathsAreUniqueAndRoutesAreNamedAfterTheKeys(): void
    {
        $paths = array_map(static fn (NavItem $i): string => $i->path, $this->catalog()->all());
        self::assertSame($paths, array_values(array_unique($paths)));

        foreach ($this->catalog()->all() as $item) {
            // K14: the design system page predates the catalog and keeps its route name.
            self::assertSame('design-system' === $item->key ? 'admin_design_system' : $item->key, $item->routeName);
            self::assertStringStartsWith('/', $item->path);
            self::assertStringStartsWith('lucide:', $item->icon);
        }
    }

    public function testMilestones(): void
    {
        $milestones = array_map(static fn (NavItem $i): ?string => $i->milestone, $this->byKey());

        self::assertSame([
            'home' => null,
            'instances' => null,
            'projects' => null,
            'jobs' => 'M0.04',
            'webhooks' => 'M0.08',
            'schedules' => 'M0.09',
            'secrets' => null,
            'users' => null,
            'settings' => null,
            'design-system' => null,
        ], $milestones);
    }

    public function testForCurrentUserFiltersByGrantedRole(): void
    {
        $member = $this->catalog(['ROLE_USER']);
        self::assertSame(['home', 'instances'], array_map(static fn (NavItem $i): string => $i->key, $member->forCurrentUser()));

        $admin = $this->catalog(['ROLE_USER', 'ROLE_ADMIN']);
        self::assertSame(self::KEYS, array_map(static fn (NavItem $i): string => $i->key, $admin->forCurrentUser()));

        self::assertSame([], $this->catalog([])->forCurrentUser());
    }

    /**
     * @return array<string, NavItem>
     */
    private function byKey(): array
    {
        $byKey = [];
        foreach ($this->catalog()->all() as $item) {
            $byKey[$item->key] = $item;
        }

        return $byKey;
    }

    /**
     * @param list<string> $granted
     */
    private function catalog(array $granted = []): NavigationCatalog
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => \in_array($attribute, $granted, true));

        return new NavigationCatalog($checker);
    }
}
