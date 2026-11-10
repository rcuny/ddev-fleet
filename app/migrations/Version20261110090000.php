<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-220: `pending_redeploy`, one row per instance for a webhook redeploy that arrived while a job was active.
 */
final class Version20261110090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending_redeploy (a webhook redeploy waiting for the instance to be free).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pending_redeploy (instance_id VARCHAR(63) NOT NULL, branch VARCHAR(254) DEFAULT NULL, template VARCHAR(63) DEFAULT NULL, requested_by VARCHAR(254) NOT NULL, requested_at DATETIME NOT NULL, requests INTEGER NOT NULL, PRIMARY KEY (instance_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pending_redeploy');
    }
}
