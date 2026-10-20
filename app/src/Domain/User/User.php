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

    /** A site-only account opens the instance sites of its projects and nothing else: no Fleet UI, no email, never an administrator. */
    #[ORM\Column(name: 'site_only', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $siteOnly = false;

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
        bool $siteOnly = false,
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
        $this->siteOnly = $siteOnly;
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

    public function isSiteOnly(): bool
    {
        return $this->siteOnly;
    }

    public function changeSiteOnly(bool $siteOnly): void
    {
        $this->siteOnly = $siteOnly;
        $this->touch();
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

    /** The group users.yml gives every site-only user; the Fleet app refuses anyone who carries it. Written by Fleet only. */
    public const string SITE_ONLY_GROUP = 'fleet-site-only';

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
            if (self::SITE_ONLY_GROUP === $group) {
                throw new \InvalidArgumentException(\sprintf('The group "%s" is reserved for site-only users.', $group));
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
