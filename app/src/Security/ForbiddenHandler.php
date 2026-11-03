<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Twig\Environment;

/**
 * An authenticated user who lacks the role gets the 403 inside the app shell (the unauthenticated
 * case stays the entry point's plain-text 401). Registered on the firewall because a Twig error
 * page override is not used in debug mode.
 */
final readonly class ForbiddenHandler implements AccessDeniedHandlerInterface
{
    public function __construct(private Environment $twig, private Security $security)
    {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new Response($this->twig->render('error/403.html.twig', ['siteOnly' => $this->security->getUser() instanceof FleetUser && $this->security->getUser()->isSiteOnly()]), Response::HTTP_FORBIDDEN);
    }
}
