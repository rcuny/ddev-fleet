<?php

declare(strict_types=1);

namespace App\Api\Token;

use App\Domain\ApiToken\ApiScope;
use App\Domain\ApiToken\ApiToken;
use App\Domain\ApiToken\ApiTokenRepository;
use App\Fleet\Audit\AuditLog;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Issues, rotates and revokes API tokens. Used by the CLI and the admin UI (FLE-273 follow-ups); every change is audited.
 */
final readonly class ApiTokenIssuer
{
    public const int MAX_GRACE_SECONDS = 3600;

    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,31}$/D';
    private const string PROJECT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';
    private const string GLOB_PATTERN = '/^[A-Za-z0-9._*?-]{1,64}$/D';

    public function __construct(
        private ApiTokenRepository $tokens,
        private AuditLog $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<ApiScope> $scopes
     * @param list<string>   $projects      project names, or ["*"] for a read-only token
     * @param list<string>   $instanceGlobs
     * @param list<string>   $ipAllow       CIDR ranges or addresses
     *
     * @throws InvalidApiTokenRequest
     */
    public function issue(
        string $name,
        string $description,
        array $scopes,
        array $projects,
        array $instanceGlobs,
        array $ipAllow,
        \DateTimeImmutable $expiresAt,
        string $createdBy,
        ?int $rotatedFrom = null,
    ): IssuedToken {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidApiTokenRequest('The name is 1 to 32 characters: lower-case letters, digits, ".", "_" and "-".');
        }
        $scopes = self::uniqueScopes($scopes);
        $projects = array_values(array_unique($projects));
        foreach ($projects as $project) {
            if (ApiTokenPolicy::WILDCARD !== $project && 1 !== preg_match(self::PROJECT_PATTERN, $project)) {
                throw new InvalidApiTokenRequest('Invalid project name in the token.');
            }
        }
        $instanceGlobs = array_values(array_unique($instanceGlobs));
        foreach ($instanceGlobs as $glob) {
            if (1 !== preg_match(self::GLOB_PATTERN, $glob)) {
                throw new InvalidApiTokenRequest('Invalid instance pattern in the token.');
            }
        }
        $ipAllow = array_values(array_unique($ipAllow));
        foreach ($ipAllow as $range) {
            if (!self::isIpOrCidr($range)) {
                throw new InvalidApiTokenRequest('Invalid IP address or CIDR range in the token.');
            }
        }

        $now = $this->clock->now();
        ApiTokenPolicy::validate($scopes, $projects, $ipAllow, $now, $expiresAt);

        $secret = TokenFormat::generate();
        $token = new ApiToken(
            $name,
            mb_strcut($description, 0, ApiToken::DESCRIPTION_MAX),
            TokenFormat::hash($secret),
            TokenFormat::hint($secret),
            array_map(static fn (ApiScope $scope): string => $scope->value, $scopes),
            $projects,
            $instanceGlobs,
            $ipAllow,
            mb_strcut($createdBy, 0, 64),
            $now,
            $expiresAt,
            $rotatedFrom,
        );
        $this->tokens->save($token);
        $this->audit->record($createdBy, null === $rotatedFrom ? AuditLog::API_TOKEN_CREATE : AuditLog::API_TOKEN_ROTATE, 'token:'.$token->id().':'.$name);

        return new IssuedToken($token, $secret);
    }

    /**
     * A successor with the same scopes and restrictions. The old token dies at once, or after $graceSeconds (at most one hour).
     */
    public function rotate(ApiToken $old, string $actor, int $graceSeconds = 0, ?\DateTimeImmutable $expiresAt = null): IssuedToken
    {
        $now = $this->clock->now();
        if ($old->isRevoked()) {
            throw new InvalidApiTokenRequest('A revoked token cannot be rotated; issue a new one.');
        }
        if ($old->isExpired($now)) {
            throw new InvalidApiTokenRequest('An expired token cannot be rotated; issue a new one.');
        }
        $scopes = array_values(array_filter(array_map(ApiScope::tryFrom(...), $old->scopes())));
        $successor = $this->issue(
            $old->name(),
            $old->description(),
            $scopes,
            $old->projects(),
            $old->instanceGlobs(),
            $old->ipAllow(),
            $expiresAt ?? $now->modify('+'.ApiTokenPolicy::defaultLifetimeDays($scopes).' days'),
            $actor,
            $old->id(),
        );

        $grace = max(0, min(self::MAX_GRACE_SECONDS, $graceSeconds));
        $subject = 'token:'.$old->id().':'.$old->name();
        if (0 === $grace) {
            $old->revoke($now);
            $this->tokens->save($old);
            $this->audit->record($actor, AuditLog::API_TOKEN_REVOKE, $subject.' (rotated)');
        } else {
            $old->expireNoLaterThan($now->modify('+'.$grace.' seconds'));
            $this->tokens->save($old);
            $this->audit->record($actor, AuditLog::API_TOKEN_EXPIRE, $subject.' (rotated, grace '.$grace.'s)');
        }

        return $successor;
    }

    public function revoke(ApiToken $token, string $actor): void
    {
        if ($token->isRevoked()) {
            return;
        }
        $token->revoke($this->clock->now());
        $this->tokens->save($token);
        $this->audit->record($actor, AuditLog::API_TOKEN_REVOKE, 'token:'.$token->id().':'.$token->name());
    }

    /**
     * @param list<ApiScope> $scopes
     *
     * @return list<ApiScope>
     */
    private static function uniqueScopes(array $scopes): array
    {
        $seen = [];
        foreach ($scopes as $scope) {
            $seen[$scope->value] = $scope;
        }

        return array_values($seen);
    }

    private static function isIpOrCidr(string $range): bool
    {
        $parts = explode('/', $range, 2);
        if (false === filter_var($parts[0], \FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!isset($parts[1])) {
            return true;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;

        return 1 === preg_match('/^\d{1,3}$/D', $parts[1]) && (int) $parts[1] <= $max && IpUtils::checkIp($parts[0], $range);
    }
}
