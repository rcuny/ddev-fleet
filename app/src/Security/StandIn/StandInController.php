<?php

declare(strict_types=1);

namespace App\Security\StandIn;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Development/test only: the role switch. Sets the stand-in cookie and redirects to a same-site path.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
final class StandInController
{
    private const string NEXT_PATTERN = '#^/(?!/)[^\r\n\\\\]*$#D';

    #[Route('/_dev/stand-in/{role}', name: 'dev_stand_in', requirements: ['role' => 'admin|member|none'], methods: ['GET'])]
    public function __invoke(string $role, Request $request): RedirectResponse
    {
        $next = $request->query->get('next');
        $target = \is_string($next) && 1 === preg_match(self::NEXT_PATTERN, $next) ? $next : '/';

        $response = new RedirectResponse($target);
        $response->headers->setCookie(
            Cookie::create(StandInSettings::COOKIE, $role, 0, '/', null, null, true, false, Cookie::SAMESITE_LAX),
        );

        return $response;
    }
}
