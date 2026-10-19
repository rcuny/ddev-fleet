<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The audit log (M0.05). Project-scope secrets reuse the secret table of M0.02 (scope "project").
 */
final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create audit_entry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, actor VARCHAR(64) NOT NULL, action VARCHAR(48) NOT NULL, subject VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_audit_entry_created ON audit_entry (created_at)');
        $this->addSql('CREATE INDEX idx_audit_entry_action ON audit_entry (action, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_entry');
    }
}
