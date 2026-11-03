<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The cap on one browser upload into a project's assets (M0.06a), 2 GiB by default.
 */
final class Version20261019153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add host_settings.asset_upload_max_bytes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_settings ADD COLUMN asset_upload_max_bytes INTEGER DEFAULT 2147483648 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_settings DROP COLUMN asset_upload_max_bytes');
    }
}
