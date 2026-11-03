<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One active job per instance, enforced by the database (FLE-64): two requests that both pass the busy check cannot
 * both insert a job. `active_instance_id` holds the instance id while a job is queued or running and is null after.
 */
final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add job.active_instance_id with a unique index: at most one queued or running job per instance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD COLUMN active_instance_id VARCHAR(63) DEFAULT NULL');
        // Rows that predate the rule: the oldest active job of an instance owns it, any other stays unmarked.
        $this->addSql("UPDATE job SET active_instance_id = instance_id WHERE status IN ('queued', 'running') AND instance_id IS NOT NULL AND id = (SELECT MIN(j2.id) FROM job j2 WHERE j2.instance_id = job.instance_id AND j2.status IN ('queued', 'running'))");
        $this->addSql('CREATE UNIQUE INDEX uniq_job_active_instance ON job (active_instance_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_job_active_instance');
        $this->addSql('ALTER TABLE job DROP COLUMN active_instance_id');
    }
}
