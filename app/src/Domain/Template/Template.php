<?php

declare(strict_types=1);

namespace App\Domain\Template;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Domain\Project\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One way to deploy a project: the Drupal environment and the command lines run after a deploy (`ddev exec`) and
 * typed into the two instance terminals. Lists keep their order and duplicates. The entity only stores.
 */
#[ORM\Entity]
#[ORM\Table(name: 'template')]
#[ORM\UniqueConstraint(name: 'uniq_template_project_name', columns: ['project_id', 'name'])]
class Template
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'templates')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(type: Types::STRING, length: Project::NAME_MAX)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $drupalEnv = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $postDeploy = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tty1 = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tty2 = [];

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Project $project, string $name, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->project = $project;
        $this->name = $name;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function drupalEnv(): ?string
    {
        return $this->drupalEnv;
    }

    /**
     * @return list<string>
     */
    public function postDeploy(): array
    {
        return $this->postDeploy;
    }

    /**
     * @return list<string>
     */
    public function tty1(): array
    {
        return $this->tty1;
    }

    /**
     * @return list<string>
     */
    public function tty2(): array
    {
        return $this->tty2;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeDrupalEnv(?string $drupalEnv, ?\DateTimeImmutable $now = null): void
    {
        $this->drupalEnv = $drupalEnv;
        $this->touch($now);
    }

    /**
     * @param list<string> $postDeploy
     * @param list<string> $tty1
     * @param list<string> $tty2
     */
    public function changeCommands(array $postDeploy, array $tty1, array $tty2, ?\DateTimeImmutable $now = null): void
    {
        $this->postDeploy = $postDeploy;
        $this->tty1 = $tty1;
        $this->tty2 = $tty2;
        $this->touch($now);
    }

    private function touch(?\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}
