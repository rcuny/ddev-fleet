<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Setup\SetupStep;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every wizard page shares. Access (token session, open/closed) is the SetupAccessSubscriber's job and has
 * happened before any of these run.
 */
abstract class AbstractSetupController extends AbstractController
{
    /**
     * @param array<string, mixed> $parameters
     */
    protected function page(string $template, SetupStep $step, array $parameters = [], int $status = Response::HTTP_OK): Response
    {
        return $this->render($template, ['step' => $step, 'steps' => SetupStep::cases()] + $parameters, new Response('', $status));
    }

    /**
     * The fixed 403 body (see SetupAccessSubscriber).
     */
    protected function denied(int $status = Response::HTTP_FORBIDDEN, bool $throttled = false): Response
    {
        return $this->render('setup/denied.html.twig', ['throttled' => $throttled], new Response('', $status));
    }

    /**
     * A plain (non-Symfony-form) POST must carry the stateless CSRF token: the hidden `_token` field, accepted only
     * for a same-origin request (Sec-Fetch-Site / Origin).
     */
    protected function csrfValid(Request $request): bool
    {
        $token = $request->request->all()['_token'] ?? null;

        return \is_string($token) && $this->isCsrfTokenValid('submit', $token);
    }

    protected function csrfRefused(): Response
    {
        return $this->render('setup/denied.html.twig', ['csrf' => true], new Response('', Response::HTTP_FORBIDDEN));
    }
}
