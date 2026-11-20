<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-363: instance protection, a lock that refuses every destroy until an admin removes it.
 */
final class Version20261120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance.protected, protected_at and protected_by.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instance ADD COLUMN protected BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE instance ADD COLUMN protected_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE instance ADD COLUMN protected_by VARCHAR(254) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instance DROP COLUMN protected_by');
        $this->addSql('ALTER TABLE instance DROP COLUMN protected_at');
        $this->addSql('ALTER TABLE instance DROP COLUMN protected');
    }
}
