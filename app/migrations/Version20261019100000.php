<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Host settings fields for the host page and the backups (M0.12a): backup retention, the git bot identity and the
 * non-secret notification e-mail settings.
 */
final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add backup retention, git identity and notification e-mail fields to host_settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_settings ADD COLUMN backup_retention_days INTEGER DEFAULT 14 NOT NULL');
        $this->addSql('ALTER TABLE host_settings ADD COLUMN git_user_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE host_settings ADD COLUMN git_user_email VARCHAR(254) DEFAULT NULL');
        $this->addSql('ALTER TABLE host_settings ADD COLUMN smtp_host VARCHAR(253) DEFAULT NULL');
        $this->addSql('ALTER TABLE host_settings ADD COLUMN smtp_port INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE host_settings ADD COLUMN smtp_user VARCHAR(254) DEFAULT NULL');
        $this->addSql('ALTER TABLE host_settings ADD COLUMN mail_from VARCHAR(254) DEFAULT NULL');
        $this->addSql('ALTER TABLE host_settings ADD COLUMN mail_to VARCHAR(254) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        foreach (['backup_retention_days', 'git_user_name', 'git_user_email', 'smtp_host', 'smtp_port', 'smtp_user', 'mail_from', 'mail_to'] as $column) {
            $this->addSql('ALTER TABLE host_settings DROP COLUMN '.$column);
        }
    }
}
