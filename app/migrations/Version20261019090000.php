<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Projects, templates and the named-port catalogue (M0.03).
 */
final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create project, template, named_port and project_port.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE named_port (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(40) NOT NULL, public_port INTEGER NOT NULL, router_port INTEGER NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_named_port_name ON named_port (name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_named_port_public ON named_port (public_port)');
        $this->addSql('CREATE UNIQUE INDEX uniq_named_port_router ON named_port (router_port)');
        $this->addSql('CREATE TABLE project (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(40) NOT NULL, git VARCHAR(2048) NOT NULL, default_branch VARCHAR(200) DEFAULT NULL, default_template VARCHAR(40) DEFAULT NULL, additional_hostnames CLOB NOT NULL, git_bot_mode VARCHAR(16) NOT NULL, git_bot_name VARCHAR(255) DEFAULT NULL, git_bot_email VARCHAR(254) DEFAULT NULL, issue_id_regexp VARCHAR(200) DEFAULT NULL, display_submodule_branch VARCHAR(200) DEFAULT NULL, typesense BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_name ON project (name)');
        $this->addSql('CREATE TABLE project_port (project_id INTEGER NOT NULL, named_port_id INTEGER NOT NULL, PRIMARY KEY (project_id, named_port_id), CONSTRAINT FK_7A0095D4166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A0095D482146020 FOREIGN KEY (named_port_id) REFERENCES named_port (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7A0095D4166D1F9C ON project_port (project_id)');
        $this->addSql('CREATE INDEX IDX_7A0095D482146020 ON project_port (named_port_id)');
        $this->addSql('CREATE TABLE template (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(40) NOT NULL, drupal_env VARCHAR(64) DEFAULT NULL, post_deploy CLOB NOT NULL, tty1 CLOB NOT NULL, tty2 CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_97601F83166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_template_project_name ON template (project_id, name)');
        $this->addSql('CREATE INDEX IDX_97601F83166D1F9C ON template (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE project_port');
        $this->addSql('DROP TABLE template');
        $this->addSql('DROP TABLE named_port');
        $this->addSql('DROP TABLE project');
    }
}
