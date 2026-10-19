<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ui\NavigationCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages that exist so the navigation is complete; each names the milestone that fills it.
 * Route names match the catalog keys. Access to /admin/* is decided by the ^/admin access rule.
 */
final class PlaceholderController extends AbstractController
{
    public function __construct(private readonly NavigationCatalog $catalog)
    {
    }

    #[Route('/instances', name: 'instances', defaults: ['key' => 'instances'], methods: ['GET'])]
    #[Route('/admin/jobs', name: 'jobs', defaults: ['key' => 'jobs'], methods: ['GET'])]
    #[Route('/admin/webhooks', name: 'webhooks', defaults: ['key' => 'webhooks'], methods: ['GET'])]
    #[Route('/admin/schedules', name: 'schedules', defaults: ['key' => 'schedules'], methods: ['GET'])]
    #[Route('/admin/secrets', name: 'secrets', defaults: ['key' => 'secrets'], methods: ['GET'])]
    #[Route('/admin/users', name: 'users', defaults: ['key' => 'users'], methods: ['GET'])]
    public function __invoke(string $key): Response
    {
        $item = $this->catalog->find($key) ?? throw new NotFoundHttpException();

        return $this->render('placeholder.html.twig', ['item' => $item]);
    }
}
