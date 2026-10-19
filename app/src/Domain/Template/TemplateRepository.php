<?php

declare(strict_types=1);

namespace App\Domain\Template;

use App\Domain\Project\Project;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TemplateRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(Project $project, string $name): ?Template
    {
        return $this->em->getRepository(Template::class)->findOneBy(['project' => $project, 'name' => $name]);
    }

    public function save(Template $template): void
    {
        $this->em->persist($template);
        $this->em->flush();
    }

    public function remove(Template $template): void
    {
        $this->em->remove($template);
        $this->em->flush();
    }
}
