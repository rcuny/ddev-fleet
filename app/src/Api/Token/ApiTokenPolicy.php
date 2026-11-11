<?php

declare(strict_types=1);

namespace App\Api\Token;

use App\Domain\ApiToken\ApiScope;
use App\Domain\ApiToken\ApiToken;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The rules a token must obey when it is issued (FLE-265 design, sections 3.2 and 4.3). Pure: no I/O.
 */
final class ApiTokenPolicy
{
    public const int MAX_DAYS = 365;
    public const int TERMINAL_WRITE_MAX_DAYS = 7;
    public const string WILDCARD = '*';

    /**
     * @param list<ApiScope> $scopes
     */
    public static function maxLifetimeDays(array $scopes): int
    {
        return \in_array(ApiScope::TerminalWrite, $scopes, true) ? self::TERMINAL_WRITE_MAX_DAYS : self::MAX_DAYS;
    }

    /**
     * @param list<ApiScope> $scopes
     */
    public static function defaultLifetimeDays(array $scopes): int
    {
        return match (true) {
            \in_array(ApiScope::TerminalWrite, $scopes, true), \in_array(ApiScope::TerminalRead, $scopes, true) => 1,
            \in_array(ApiScope::Lifecycle, $scopes, true), \in_array(ApiScope::LifecycleDestroy, $scopes, true) => 30,
            default => 90,
        };
    }

    /**
     * The terminal:write rules checked again on the stored token at request time (FLE-286 SR4): a row edited in the database
     * after issue (no IP list, a long expiry, an added deploy scope, no instance pattern) must not keep a working terminal
     * token. Null when the token is acceptable, else a short reason for the audit log.
     */
    public static function terminalWriteProblem(ApiToken $token, string $clientIp): ?string
    {
        $scopes = $token->scopes();
        foreach ([ApiScope::Deploy, ApiScope::Lifecycle, ApiScope::LifecycleDestroy] as $other) {
            if (\in_array($other->value, $scopes, true)) {
                return 'combined_scope';
            }
        }
        if ([] === $token->ipAllow() || !IpUtils::checkIp($clientIp, $token->ipAllow())) {
            return 'ip_not_allowed';
        }
        if ($token->expiresAt() > $token->createdAt()->modify('+'.self::TERMINAL_WRITE_MAX_DAYS.' days')) {
            return 'lifetime_too_long';
        }
        if ([] === $token->instanceGlobs()) {
            return 'no_instance_pattern';
        }

        return null;
    }

    /**
     * @param list<ApiScope> $scopes
     * @param list<string>   $projects
     * @param list<string>   $ipAllow
     *
     * @throws InvalidApiTokenRequest
     */
    public static function validate(
        array $scopes,
        array $projects,
        array $ipAllow,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt,
    ): void {
        if ([] === $scopes) {
            throw new InvalidApiTokenRequest('A token needs at least one scope.');
        }
        if ([] === $projects) {
            throw new InvalidApiTokenRequest('A token needs a project list.');
        }
        if (self::wildcardProjects($projects) && $projects !== [self::WILDCARD]) {
            throw new InvalidApiTokenRequest('"*" cannot be mixed with project names.');
        }
        if ($projects === [self::WILDCARD] && $scopes !== [ApiScope::Read]) {
            throw new InvalidApiTokenRequest('"*" (every project) is allowed for a read-only token only.');
        }
        if (\in_array(ApiScope::LifecycleDestroy, $scopes, true) && !\in_array(ApiScope::Lifecycle, $scopes, true)) {
            throw new InvalidApiTokenRequest('lifecycle:destroy is a flag on top of lifecycle; add lifecycle.');
        }
        if (\in_array(ApiScope::TerminalWrite, $scopes, true)) {
            foreach ([ApiScope::Deploy, ApiScope::Lifecycle, ApiScope::LifecycleDestroy] as $other) {
                if (\in_array($other, $scopes, true)) {
                    throw new InvalidApiTokenRequest('terminal:write cannot be combined with deploy or lifecycle.');
                }
            }
            if ([] === $ipAllow) {
                throw new InvalidApiTokenRequest('terminal:write requires an IP allow-list.');
            }
        }
        if ($expiresAt <= $now) {
            throw new InvalidApiTokenRequest('The expiry must be in the future.');
        }
        $max = self::maxLifetimeDays($scopes);
        if ($expiresAt > $now->modify('+'.$max.' days')) {
            throw new InvalidApiTokenRequest(\sprintf('This token cannot live longer than %d days.', $max));
        }
    }

    /**
     * @param list<string> $projects
     */
    private static function wildcardProjects(array $projects): bool
    {
        return \in_array(self::WILDCARD, $projects, true);
    }
}
