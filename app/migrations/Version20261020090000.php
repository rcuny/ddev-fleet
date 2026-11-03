<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Instances and jobs (M0.04): the instance table (primary key `{project}--{label}`, project ON DELETE RESTRICT) and the
 * job history (no foreign key on instance_id: the history outlives the instance).
 */
final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create instance and job.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE instance (id VARCHAR(63) NOT NULL, label VARCHAR(40) NOT NULL, template_name VARCHAR(40) NOT NULL, branch VARCHAR(200) NOT NULL, state VARCHAR(16) NOT NULL, head_sha VARCHAR(40) DEFAULT NULL, ram_mib INTEGER DEFAULT NULL, requested_by VARCHAR(254) NOT NULL, last_observed_at DATETIME DEFAULT NULL, deployed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_4230B1DE166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_instance_project ON instance (project_id)');
        $this->addSql('CREATE INDEX idx_instance_state ON instance (state)');
        $this->addSql('CREATE TABLE job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(32) NOT NULL, instance_id VARCHAR(63) DEFAULT NULL, project_name VARCHAR(40) DEFAULT NULL, status VARCHAR(16) NOT NULL, trigger_kind VARCHAR(16) NOT NULL, requested_by VARCHAR(254) NOT NULL, params CLOB NOT NULL, batch_id VARCHAR(36) DEFAULT NULL, sequential BOOLEAN NOT NULL, retry_of INTEGER DEFAULT NULL, exit_code INTEGER DEFAULT NULL, error VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_job_status ON job (status)');
        $this->addSql('CREATE INDEX idx_job_instance ON job (instance_id)');
        $this->addSql('CREATE INDEX idx_job_batch ON job (batch_id)');
        $this->addSql('CREATE INDEX idx_job_created ON job (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE job');
        $this->addSql('DROP TABLE instance');
    }
}
