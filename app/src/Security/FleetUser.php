<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The identity forwarded by the authenticating proxy (or the dev stand-in). Never persisted.
 */
final readonly class FleetUser implements UserInterface
{
    /**
     * @param non-empty-string $identifier
     * @param list<string>     $groups
     */
    public function __construct(
        private string $identifier,
        private string $displayName,
        private ?string $email,
        private array $groups,
        private string $adminGroup,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        // Exact, case-sensitive match: the group name is an authorisation decision.
        return '' !== $this->adminGroup && \in_array($this->adminGroup, $this->groups, true)
            ? ['ROLE_USER', 'ROLE_ADMIN']
            : ['ROLE_USER', 'ROLE_MEMBER'];
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function email(): ?string
    {
        return $this->email;
    }
}
