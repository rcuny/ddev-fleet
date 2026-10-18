<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Trusts the identity headers set by the authenticating reverse proxy (Remote-User, Remote-Groups,
 * Remote-Email, Remote-Name), but only when the TCP peer is a trusted proxy. It is also the firewall's
 * entry point: an unauthenticated request gets a plain-text 401.
 */
final class RemoteUserAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const string USERNAME_PATTERN = '/^[A-Za-z0-9._@+-]{1,128}$/D';
    private const int MAX_TEXT = 256;
    private const int MAX_GROUPS = 64;
    private const int MAX_GROUP_LENGTH = 128;

    public function __construct(
        private readonly TrustedProxyChecker $proxies,
        #[Autowire('%fleet.admin_group%')]
        private readonly string $adminGroup,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('Remote-User');
    }

    public function authenticate(Request $request): Passport
    {
        // The raw peer address, never getClientIp(): X-Forwarded-For is attacker-controlled.
        // Known limitation (K26): behind Caddy's php_fastcgi, REMOTE_ADDR is the real client address, so with
        // the default FLEET_TRUSTED_PROXIES prod answers 401 to everyone (fails closed) until M0.02 adds a
        // shared-secret header or loopback TCP. Never widen FLEET_TRUSTED_PROXIES to "fix" that: any
        // broader range lets anyone spoof the identity headers.
        $peer = $request->server->get('REMOTE_ADDR');
        if (!\is_string($peer) || !$this->proxies->isTrusted($peer)) {
            throw new CustomUserMessageAuthenticationException('Identity headers are not accepted from this peer.');
        }

        $username = trim((string) $request->headers->get('Remote-User'));
        if (1 !== preg_match(self::USERNAME_PATTERN, $username)) {
            throw new CustomUserMessageAuthenticationException('Invalid Remote-User header.');
        }

        $groups = [];
        foreach (explode(',', (string) $request->headers->get('Remote-Groups')) as $group) {
            $group = trim($group);
            if (mb_strlen($group) > self::MAX_GROUP_LENGTH) {
                throw new CustomUserMessageAuthenticationException('Invalid Remote-Groups header.');
            }
            if ('' !== $group && !\in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }
        if (\count($groups) > self::MAX_GROUPS) {
            throw new CustomUserMessageAuthenticationException('Invalid Remote-Groups header.');
        }

        $name = self::text($request->headers->get('Remote-Name')) ?? $username;
        $email = self::text($request->headers->get('Remote-Email'));
        $adminGroup = $this->adminGroup;

        return new SelfValidatingPassport(new UserBadge(
            $username,
            static fn (): FleetUser => new FleetUser($username, $name, $email, $groups, $adminGroup),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized();
    }

    private static function unauthorized(): Response
    {
        return new Response('Unauthorized', Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private static function text(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));

        return '' === $value ? null : mb_substr($value, 0, self::MAX_TEXT);
    }
}
