<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Project memberships: which users may operate which project (M0.10).
 */
final class Version20261019130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create project_access (user and project, unique pair, both foreign keys cascade).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_access (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_11CDC01EA76ED395 FOREIGN KEY (user_id) REFERENCES fleet_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_11CDC01E166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_access_pair ON project_access (user_id, project_id)');
        $this->addSql('CREATE INDEX IDX_11CDC01EA76ED395 ON project_access (user_id)');
        $this->addSql('CREATE INDEX IDX_11CDC01E166D1F9C ON project_access (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE project_access');
    }
}
