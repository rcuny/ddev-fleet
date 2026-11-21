<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-367: optional per-project retention of CI instances.
 */
final class Version20261120220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project.ci_retention_days (null = retention off).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD COLUMN ci_retention_days INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP COLUMN ci_retention_days');
    }
}
