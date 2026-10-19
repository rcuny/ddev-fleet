<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schedules (FLE-72): the recurring jobs and their run history. `schedule_run` has a unique (schedule, claim) so an
 * occurrence is claimed once, and cascades with its schedule.
 */
final class Version20261021090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add schedule and schedule_run.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE schedule (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(40) NOT NULL, cron VARCHAR(100) NOT NULL, timezone VARCHAR(64) NOT NULL, action VARCHAR(32) NOT NULL, instance_id VARCHAR(63) DEFAULT NULL, enabled BOOLEAN NOT NULL, last_occurrence DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_name ON schedule (name)');
        $this->addSql('CREATE TABLE schedule_run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, schedule_id INTEGER NOT NULL, occurrence DATETIME NOT NULL, claim DATETIME DEFAULT NULL, trigger_kind VARCHAR(16) NOT NULL, outcome VARCHAR(16) NOT NULL, job_id INTEGER DEFAULT NULL, detail VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_SCHEDULE_RUN_SCHEDULE FOREIGN KEY (schedule_id) REFERENCES schedule (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_schedule_run_schedule ON schedule_run (schedule_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_run_claim ON schedule_run (schedule_id, claim)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE schedule_run');
        $this->addSql('DROP TABLE schedule');
    }
}
