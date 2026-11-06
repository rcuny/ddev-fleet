<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\CurrentActor;
use App\Security\ProjectVoter;
use App\Security\Reauth\ReauthService;
use App\Security\Reauth\ReauthThrottled;
use App\Security\Reauth\ReauthWindow;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The re-auth page: the admin re-enters their password to open a short window (ReauthWindow::SECONDS) for the gated
 * actions (reveal a secret, export with sealed secrets). The password is never stored, logged or echoed back.
 */
final class ReauthController extends AbstractController
{
    /** Where the person returns to: a page of the admin area, never another host or a path with a scheme. */
    private const string NEXT = '#^/admin(?:/(?!\.+(?:/|$))[A-Za-z0-9._-]+)*$#D';

    public function __construct(
        private readonly ReauthService $reauth,
        private readonly CurrentActor $actor,
    ) {
    }

    #[Route('/admin/reauth', name: 'reauth', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);

        return $this->page(self::next($request->query->get('next')));
    }

    #[Route('/admin/reauth', name: 'reauth_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::SETTINGS);
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $next = self::next($request->request->get('next'));
        $password = $request->request->get('password');
        $password = \is_string($password) ? $password : '';

        try {
            $ok = '' !== $password && $this->reauth->confirm($this->actor->name(), $password);
        } catch (ReauthThrottled $e) {
            $response = $this->page($next, 'Too many attempts. Try again in '.$e->retryAfter.' seconds.', Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $e->retryAfter);

            return $response;
        }

        if (!$ok) {
            return $this->page($next, 'The password is not right.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', \sprintf('Password confirmed. You have %d minutes for the action.', intdiv(ReauthWindow::SECONDS, 60)));

        return $this->redirect($next, Response::HTTP_SEE_OTHER);
    }

    private function page(string $next, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('admin/reauth/index.html.twig', [
            'next' => $next,
            'error' => $error,
            'minutes' => intdiv(ReauthWindow::SECONDS, 60),
        ], new Response(status: $status));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private static function next(mixed $value): string
    {
        return \is_string($value) && 1 === preg_match(self::NEXT, $value) ? $value : '/admin';
    }
}
