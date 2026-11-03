<?php

declare(strict_types=1);

namespace App\Security\StandIn;

use App\Security\TrustedProxyChecker;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Development/test only: authenticates as the configured admin/member identity so the app works without
 * a proxy. Never runs when a Remote-User header is present (that request belongs to RemoteUserAuthenticator) or when the request
 * came through the proxy.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
final class StandInAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly StandInSettings $settings)
    {
    }

    public function supports(Request $request): bool
    {
        if ($request->headers->has('Remote-User')) {
            return false;
        }

        // A request that came through the proxy (the FastCGI param exists only behind Caddy) is never a stand-in
        // request, even if a dev/test kernel is misdeployed behind it.
        if ($request->server->has(TrustedProxyChecker::PROXY_PARAM)) {
            return false;
        }

        return StandInSettings::NONE !== $this->role($request);
    }

    public function authenticate(Request $request): Passport
    {
        $name = $request->cookies->get(StandInSettings::USER_COOKIE);
        $user = $this->settings->userFor($this->role($request), \is_string($name) ? $name : null);
        if (null === $user) {
            throw new AuthenticationException('No stand-in identity selected.');
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

    private function role(Request $request): string
    {
        $cookie = $request->cookies->get(StandInSettings::COOKIE);

        return $this->settings->resolveRole(
            $request->headers->get(StandInSettings::HEADER),
            \is_string($cookie) ? $cookie : null,
        );
    }
}
