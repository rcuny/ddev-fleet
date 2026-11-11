<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\ApiTokenPolicy;
use App\Api\Token\ApiTokenWatch;
use App\Api\Token\InvalidApiTokenRequest;
use App\Api\Token\IssuedToken;
use App\Domain\ApiToken\ApiScope;
use App\Domain\ApiToken\ApiToken;
use App\Domain\ApiToken\ApiTokenRepository;
use App\Security\CurrentActor;
use App\Security\Reauth\ReauthGuard;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API service tokens (FLE-275): the list with an "expiring / unused" panel, create, rotate, revoke. Admins only. Every
 * change is a POST with the CSRF token and needs a recent re-auth; the secret is shown once, in the response to the POST
 * that made it (never stored, never put in a flash message or the session), with `Cache-Control: no-store`.
 */
final class ApiTokenController extends AbstractController
{
    private const string BACK = '/admin/api-tokens';

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly ApiTokenIssuer $issuer,
        private readonly ReauthGuard $reauth,
        private readonly CurrentActor $actor,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/api-tokens', name: 'api_tokens', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->page();
    }

    #[Route('/admin/api-tokens/create', name: 'api_token_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (null !== $stop = $this->gate($request, 'api-token-create', 'new')) {
            return $stop;
        }
        $scopes = [];
        foreach ($request->request->all('scopes') as $value) {
            $scope = \is_string($value) ? ApiScope::tryFrom($value) : null;
            if (null !== $scope) {
                $scopes[] = $scope;
            }
        }
        $days = $request->request->getString('expires_in');
        if ('' !== $days && 1 !== preg_match('/^[1-9]\d{0,3}$/D', $days)) {
            $this->addFlash('warning', 'Not created: the lifetime is a whole number of days.');

            return $this->back();
        }
        $lifetime = '' === $days ? ApiTokenPolicy::defaultLifetimeDays($scopes) : (int) $days;

        try {
            $issued = $this->issuer->issue(
                $request->request->getString('name'),
                $request->request->getString('description'),
                $scopes,
                self::words($request->request->getString('projects')),
                self::words($request->request->getString('instance_globs')),
                self::words($request->request->getString('ip_allow')),
                $this->clock->now()->modify('+'.$lifetime.' days'),
                $this->actor->name(),
            );
        } catch (InvalidApiTokenRequest $e) {
            $this->addFlash('warning', 'Not created: '.$e->getMessage());

            return $this->back();
        }
        $this->reauth->close();

        return $this->page($issued, 'created');
    }

    #[Route('/admin/api-tokens/{id}/rotate', name: 'api_token_rotate', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function rotate(Request $request, int $id): Response
    {
        if (null !== $stop = $this->gate($request, 'api-token-rotate', (string) $id)) {
            return $stop;
        }
        $old = $this->tokens->find($id) ?? throw $this->createNotFoundException();
        $grace = $request->request->getString('grace', '0');
        if (1 !== preg_match('/^\d{1,5}$/D', $grace) || (int) $grace > ApiTokenIssuer::MAX_GRACE_SECONDS) {
            $this->addFlash('warning', 'Not rotated: the grace period is 0 to '.ApiTokenIssuer::MAX_GRACE_SECONDS.' seconds.');

            return $this->back();
        }

        $days = $request->request->getString('expires_in');
        if ('' !== $days && 1 !== preg_match('/^[1-9]\d{0,3}$/D', $days)) {
            $this->addFlash('warning', 'Not rotated: the lifetime is a whole number of days.');

            return $this->back();
        }

        try {
            $issued = $this->issuer->rotate($old, $this->actor->name(), (int) $grace, '' === $days ? null : $this->clock->now()->modify('+'.$days.' days'));
        } catch (InvalidApiTokenRequest $e) {
            $this->addFlash('warning', 'Not rotated: '.$e->getMessage());

            return $this->back();
        }
        $this->reauth->close();

        return $this->page($issued, 'rotated');
    }

    #[Route('/admin/api-tokens/{id}/revoke', name: 'api_token_revoke', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function revoke(Request $request, int $id): Response
    {
        if (null !== $stop = $this->gate($request, 'api-token-revoke', (string) $id)) {
            return $stop;
        }
        $token = $this->tokens->find($id) ?? throw $this->createNotFoundException();
        $this->issuer->revoke($token, $this->actor->name());
        $this->reauth->close();
        $this->addFlash('success', \sprintf('Token "%s" revoked. It stops working at once.', $token->name()));

        return $this->back();
    }

    private function page(?IssuedToken $issued = null, string $what = ''): Response
    {
        $now = $this->clock->now();
        $all = $this->tokens->all();
        $response = $this->render('admin/api_tokens/index.html.twig', [
            'tokens' => $all,
            'now' => $now,
            'expiring' => ApiTokenWatch::expiring($all, $now),
            'unused' => ApiTokenWatch::unused($all, $now),
            'expiring_days' => ApiTokenWatch::EXPIRING_DAYS,
            'unused_days' => ApiTokenWatch::UNUSED_DAYS,
            'scopes' => ApiScope::cases(),
            'max_grace' => ApiTokenIssuer::MAX_GRACE_SECONDS,
            'issued' => $issued,
            'what' => $what,
            'states' => array_combine(array_map(static fn (ApiToken $t): int => (int) $t->id(), $all), array_map(static fn (ApiToken $t): string => ApiTokenWatch::state($t, $now), $all)),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @return list<string> the comma/space separated words of a form field
     */
    private static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', trim($text)) ?: [], static fn (string $w): bool => '' !== $w));
    }

    /**
     * @return Response|null the response that stops the request (re-auth needed), null to go on
     */
    private function gate(Request $request, string $action, string $subject): ?Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$this->reauth->requireRecentAuth()) {
            return $this->reauth->challenge($action, $subject, self::BACK);
        }

        return null;
    }

    private function back(): Response
    {
        return $this->redirect(self::BACK, Response::HTTP_SEE_OTHER);
    }
}
