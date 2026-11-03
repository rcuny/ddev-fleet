<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Domain\Port\NamedPort;
use App\Domain\Template\Template;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the fleet can deploy: a git repository with defaults, extra hostnames, port assignments, an optional git
 * identity and the settings the issue tracker integration reads. The entity only stores; App\Fleet\Projects validates.
 * The name is immutable: instance ids and hostnames derive from it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project')]
#[ORM\UniqueConstraint(name: 'uniq_project_name', columns: ['name'])]
class Project
{
    public const int NAME_MAX = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    private string $git;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $defaultBranch = null;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX, nullable: true)]
    private ?string $defaultTemplate = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $additionalHostnames = [];

    #[ORM\Column(type: Types::STRING, length: 16, enumType: GitBotMode::class)]
    private GitBotMode $gitBotMode = GitBotMode::Inherit;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $gitBotName = null;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $gitBotEmail = null;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $issueIdRegexp = null;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $displaySubmoduleBranch = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $typesense = false;

    /** @var Collection<int, NamedPort> */
    #[ORM\ManyToMany(targetEntity: NamedPort::class)]
    #[ORM\JoinTable(name: 'project_port')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'named_port_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $ports;

    /** @var Collection<int, Template> */
    #[ORM\OneToMany(targetEntity: Template::class, mappedBy: 'project')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $templates;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $git, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->name = $name;
        $this->git = $git;
        $this->ports = new ArrayCollection();
        $this->templates = new ArrayCollection();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function git(): string
    {
        return $this->git;
    }

    public function defaultBranch(): ?string
    {
        return $this->defaultBranch;
    }

    public function defaultTemplate(): ?string
    {
        return $this->defaultTemplate;
    }

    /**
     * @return list<string>
     */
    public function additionalHostnames(): array
    {
        return $this->additionalHostnames;
    }

    public function gitBotMode(): GitBotMode
    {
        return $this->gitBotMode;
    }

    public function gitBotName(): ?string
    {
        return $this->gitBotName;
    }

    public function gitBotEmail(): ?string
    {
        return $this->gitBotEmail;
    }

    public function issueIdRegexp(): ?string
    {
        return $this->issueIdRegexp;
    }

    public function displaySubmoduleBranch(): ?string
    {
        return $this->displaySubmoduleBranch;
    }

    public function typesense(): bool
    {
        return $this->typesense;
    }

    /**
     * @return list<NamedPort> ordered by name
     */
    public function ports(): array
    {
        return array_values($this->ports->toArray());
    }

    /**
     * @return list<Template> ordered by name
     */
    public function templates(): array
    {
        return array_values($this->templates->toArray());
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeGit(string $git, ?\DateTimeImmutable $now = null): void
    {
        $this->git = $git;
        $this->touch($now);
    }

    public function changeDefaults(?string $branch, ?string $template, ?\DateTimeImmutable $now = null): void
    {
        $this->defaultBranch = $branch;
        $this->defaultTemplate = $template;
        $this->touch($now);
    }

    /**
     * @param list<string> $hostnames
     */
    public function changeHostnames(array $hostnames, ?\DateTimeImmutable $now = null): void
    {
        $this->additionalHostnames = $hostnames;
        $this->touch($now);
    }

    /**
     * @param list<NamedPort> $ports
     */
    public function changePorts(array $ports, ?\DateTimeImmutable $now = null): void
    {
        $this->ports->clear();
        foreach ($ports as $port) {
            if (!$this->ports->contains($port)) {
                $this->ports->add($port);
            }
        }
        $this->touch($now);
    }

    public function changeGitBot(GitBotMode $mode, ?string $name, ?string $email, ?\DateTimeImmutable $now = null): void
    {
        $this->gitBotMode = $mode;
        $this->gitBotName = $name;
        $this->gitBotEmail = $email;
        $this->touch($now);
    }

    public function changeIssueIdRegexp(?string $regexp, ?\DateTimeImmutable $now = null): void
    {
        $this->issueIdRegexp = $regexp;
        $this->touch($now);
    }

    public function changeDisplaySubmoduleBranch(?string $path, ?\DateTimeImmutable $now = null): void
    {
        $this->displaySubmoduleBranch = $path;
        $this->touch($now);
    }

    public function changeTypesense(bool $typesense, ?\DateTimeImmutable $now = null): void
    {
        $this->typesense = $typesense;
        $this->touch($now);
    }

    private function touch(?\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}
