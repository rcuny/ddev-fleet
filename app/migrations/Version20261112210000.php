<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-299: declared links between projects (project_link) and the host's isolation mode (off) with its extra host ports.
 */
final class Version20261112210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project_link, host_settings.isolation_mode and host_settings.isolation_host_ports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_link (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, service VARCHAR(64) NOT NULL, port INTEGER NOT NULL, target_label VARCHAR(64) NOT NULL, note CLOB NOT NULL, created_by VARCHAR(128) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:utc_datetime_immutable)
        , source_id INTEGER NOT NULL, target_id INTEGER NOT NULL, CONSTRAINT FK_PROJECT_LINK_SOURCE FOREIGN KEY (source_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROJECT_LINK_TARGET FOREIGN KEY (target_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_PROJECT_LINK_SOURCE ON project_link (source_id)');
        $this->addSql('CREATE INDEX IDX_PROJECT_LINK_TARGET ON project_link (target_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_link ON project_link (source_id, target_id, service, port, target_label)');
        $this->addSql("ALTER TABLE host_settings ADD COLUMN isolation_mode VARCHAR(16) DEFAULT 'off' NOT NULL");
        $this->addSql("ALTER TABLE host_settings ADD COLUMN isolation_host_ports CLOB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE project_link');
        $this->addSql('ALTER TABLE host_settings DROP COLUMN isolation_mode');
        $this->addSql('ALTER TABLE host_settings DROP COLUMN isolation_host_ports');
    }
}
