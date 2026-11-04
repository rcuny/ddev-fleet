<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Fleet\Upgrade\ChangelogCache;
use App\Fleet\Upgrade\CheckNowThrottle;
use App\Fleet\Upgrade\UpgradeStatusReader;
use App\Message\RefreshReleaseCatalog;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Upgrade page (/admin/host/upgrade, administrators only: the ^/admin access rule decides): installed against
 * latest, the changelog and rollout notes of what an upgrade brings, and the command to run. It shows status only:
 * the upgrade itself is `sudo fleet upgrade` on the host (plan ruling R2), so nothing here changes the host.
 * Rendering reads the cache and never the network; "Check now" is the one action and asks for a fresh lookup.
 */
final class UpgradeController extends AbstractController
{
    public function __construct(
        private readonly UpgradeStatusReader $status,
        private readonly ChangelogCache $changelog,
        private readonly CheckNowThrottle $throttle,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/host/upgrade', name: 'upgrade', methods: ['GET'])]
    public function index(): Response
    {
        $status = $this->status->read();
        $latest = $status->latest();
        $excerpt = $status->isBehind() ? $this->changelog->read() : null;
        if (null !== $excerpt && (null === $latest || 0 !== $excerpt->upTo->compare($latest->version))) {
            $excerpt = null; // an excerpt for an older "latest": do not show text that is not what would be installed
        }

        $actions = [];
        foreach ($excerpt->sections ?? [] as $section) {
            foreach ($section->rollout->actions() as $action) {
                $actions[] = ['version' => (string) $section->version, 'text' => $action];
            }
        }

        return $this->render('admin/host/upgrade.html.twig', [
            'status' => $status,
            'excerpt' => $excerpt,
            'actions' => $actions,
            'now' => $this->clock->now(),
        ]);
    }

    #[Route('/admin/host/upgrade/check', name: 'upgrade_check', methods: ['POST'])]
    public function check(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('submit', $token)) {
            $this->addFlash('error', 'The form expired. Reload the page and try again.');

            return $this->redirectToRoute('upgrade');
        }

        $wait = $this->throttle->attempt($this->actor());
        if (null !== $wait) {
            $this->addFlash('error', \sprintf('Releases were checked a moment ago. Try again in %d seconds.', $wait));

            return $this->redirectToRoute('upgrade');
        }

        $this->bus->dispatch(new RefreshReleaseCatalog());
        $this->addFlash('success', 'Looked up the published releases.');

        return $this->redirectToRoute('upgrade');
    }

    private function actor(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'admin';
    }
}
