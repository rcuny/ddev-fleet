<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Fleet\Audit\AuditLog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The audit log: the latest 200 entries, optionally of one action. Behind the `^/admin` access rule.
 */
final class AuditController extends AbstractController
{
    public const int LIMIT = 200;

    public function __construct(private readonly AuditLog $audit)
    {
    }

    #[Route('/admin/audit', name: 'audit', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $actions = $this->audit->actions();
        $action = $request->query->get('action');
        $action = \is_string($action) && \in_array($action, $actions, true) ? $action : null;

        return $this->render('admin/audit/index.html.twig', [
            'entries' => $this->audit->latest(self::LIMIT, $action),
            'actions' => $actions,
            'action' => $action,
            'limit' => self::LIMIT,
        ]);
    }
}
