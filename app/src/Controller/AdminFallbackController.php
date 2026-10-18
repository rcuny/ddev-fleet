<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lowest-priority catch-all under /admin. The router runs before the firewall, so a request that
 * matches no route would answer 404 to everyone; with this route the firewall and the ROLE_ADMIN
 * access rule see every /admin request first (anonymous 401, member 403) and only an admin reaches
 * the 404. Real /admin pages are ordinary routes and win by priority.
 */
final class AdminFallbackController
{
    #[Route('/admin{path}', name: 'admin_fallback', requirements: ['path' => '(/.*)?'], defaults: ['path' => ''], priority: -1000)]
    public function __invoke(): never
    {
        throw new NotFoundHttpException();
    }
}
