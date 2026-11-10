<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Token\ApiTokenPolicy;
use App\Domain\ApiToken\ApiScope;
use App\Domain\ApiToken\ApiToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The principal of an API request: the token itself (a service principal, like a bot user), never a person. It has
 * ROLE_API and nothing else, so no role check written for people (ROLE_ADMIN, ROLE_MEMBER) can ever pass for it.
 */
final readonly class ApiTokenUser implements UserInterface
{
    public const string ROLE = 'ROLE_API';

    /** @param list<ApiScope> $scopes */
    private function __construct(
        private int $id,
        private string $name,
        private array $scopes,
        private ApiToken $token,
    ) {
    }

    public static function fromToken(ApiToken $token): self
    {
        $scopes = [];
        foreach ($token->scopes() as $value) {
            $scope = ApiScope::tryFrom($value);
            if (null !== $scope) {
                $scopes[] = $scope;
            }
        }

        return new self((int) $token->id(), $token->name(), $scopes, $token);
    }

    public function tokenId(): int
    {
        return $this->id;
    }

    public function token(): ApiToken
    {
        return $this->token;
    }

    /** The name its changes are recorded under (audit log). */
    public function getUserIdentifier(): string
    {
        return 'token:'.$this->id.':'.$this->name;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [self::ROLE];
    }

    /**
     * @return list<ApiScope>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(ApiScope $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }

    /**
     * Whether the token's project list covers $project. The wildcard counts for a read-only token only, whatever
     * the stored list says (defence in depth behind the issuing rules).
     */
    public function allowsProject(string $project): bool
    {
        $projects = $this->token->projects();
        if (\in_array(ApiTokenPolicy::WILDCARD, $projects, true)) {
            return $this->scopes === [ApiScope::Read];
        }

        return \in_array($project, $projects, true);
    }

    /**
     * Whether $instanceId matches one of the token's instance patterns (`*` and `?` wildcards); none listed means none allowed.
     */
    public function allowsInstance(string $instanceId): bool
    {
        foreach ($this->token->instanceGlobs() as $glob) {
            if (fnmatch($glob, $instanceId, \FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    public function eraseCredentials(): void
    {
    }
}
