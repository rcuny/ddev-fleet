<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-300: `project.typesense_dashboard`, opt-in to the Typesense dashboard service (off by default).
 */
final class Version20261111130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project.typesense_dashboard.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD COLUMN typesense_dashboard BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP COLUMN typesense_dashboard');
    }
}
