<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DoctrinePendingMigrations implements PendingMigrations
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $migrations,
    ) {
    }

    public function count(): int
    {
        return \count($this->migrations->getMigrationStatusCalculator()->getNewMigrations()->getItems());
    }
}
