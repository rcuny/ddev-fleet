<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-213: `job.spawned_at`, set by the worker when it hands a queued job to a child process, so a restarted worker sees
 * the children that have not claimed their job yet and can tell when one of them is lost.
 */
final class Version20261105130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add job.spawned_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD COLUMN spawned_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job DROP COLUMN spawned_at');
    }
}
