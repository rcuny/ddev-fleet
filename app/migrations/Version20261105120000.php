<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-209: `host_settings.max_concurrent_jobs`, how many jobs of different instances the worker runs at once. 1 for every
 * existing host: the same one-after-another behaviour as before.
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add host_settings.max_concurrent_jobs (default 1).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_settings ADD COLUMN max_concurrent_jobs SMALLINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_settings DROP COLUMN max_concurrent_jobs');
    }
}
