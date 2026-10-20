<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person who can sign in through Authelia. Distinct from App\Security\FleetUser, which is the
 * identity the proxy asserts for one request.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fleet_user')]
#[ORM\UniqueConstraint(name: 'uniq_fleet_user_username', columns: ['username'])]
class User
{
    public const string USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $username;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $displayName;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $passwordHash;

    /** @var list<string> */
    #[ORM\Column(name: 'user_groups', type: Types::JSON)]
    private array $groups = [];

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $groups
     */
    public function __construct(
        string $username,
        string $displayName,
        string $email,
        #[\SensitiveParameter]
        string $passwordHash,
        array $groups = [],
        ?\DateTimeImmutable $now = null,
    ) {
        if (1 !== preg_match(self::USERNAME_PATTERN, $username)) {
            throw new \InvalidArgumentException(\sprintf('Invalid username "%s".', $username));
        }
        PasswordHash::assertArgon2id($passwordHash);
        $this->username = $username;
        $this->displayName = $displayName;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->groups = self::normaliseGroups($groups);
        $this->createdAt = $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * Whether the user is in this group. Which group makes an administrator is configuration, not a property of the
     * entity: ask App\Fleet\Auth\AdminGroup::includes().
     */
    public function isInGroup(string $group): bool
    {
        return \in_array($group, $this->groups, true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function updateProfile(string $displayName, string $email): void
    {
        $this->displayName = $displayName;
        $this->email = $email;
        $this->touch();
    }

    public function changePasswordHash(#[\SensitiveParameter] string $passwordHash): void
    {
        PasswordHash::assertArgon2id($passwordHash);
        $this->passwordHash = $passwordHash;
        $this->touch();
    }

    /**
     * @param list<string> $groups
     */
    public function changeGroups(array $groups): void
    {
        $this->groups = self::normaliseGroups($groups);
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Groups with this prefix stand for project membership and are written by Fleet only (ProjectGroup). */
    public const string RESERVED_GROUP_PREFIX = 'fleet-project-';

    /**
     * @param array<mixed> $groups
     *
     * @return list<string>
     */
    private static function normaliseGroups(array $groups): array
    {
        $clean = [];
        foreach ($groups as $group) {
            if (!\is_string($group) || '' === $group) {
                throw new \InvalidArgumentException('Groups must be non-empty strings.');
            }
            if (str_starts_with($group, self::RESERVED_GROUP_PREFIX)) {
                throw new \InvalidArgumentException(\sprintf('Groups starting with "%s" are reserved for project access.', self::RESERVED_GROUP_PREFIX));
            }
            $clean[$group] = $group;
        }

        return array_values($clean);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
