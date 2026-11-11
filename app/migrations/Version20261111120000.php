<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-298: the per-project switch that lets the deploy guard pass privileged compose overrides (`project.allow_privileged_overrides`, off).
 */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project.allow_privileged_overrides.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD COLUMN allow_privileged_overrides BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP COLUMN allow_privileged_overrides');
    }
}
