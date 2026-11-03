<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Setup\SetupGate;
use App\Setup\SetupStep;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * What every wizard page shares. Access (token session, open/closed) is decided by SetupGate: the access subscriber
 * asks it first and every action asks it again through guard().
 */
abstract class AbstractSetupController extends AbstractController
{
    private SetupGate $gate;

    #[Required]
    public function setGate(SetupGate $gate): void
    {
        $this->gate = $gate;
    }

    /**
     * Every action's first line: `if (null !== $refusal = $this->guard($request)) { return $refusal; }`. The access
     * subscriber has normally answered already; this makes each page safe on its own (a request that reaches a
     * controller by a path the listener did not recognise is refused here, with the same bodies).
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException when the host is not initialised
     */
    protected function guard(Request $request): ?Response
    {
        return $this->gate->refusal($request);
    }

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
