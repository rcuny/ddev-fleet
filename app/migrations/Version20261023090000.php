<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The in-network port mirror switch (FLE-92): on by default, so a host that already had named ports mirrors them at the
 * next sync.
 */
final class Version20261023090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the port mirror flag to host_settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_settings ADD COLUMN port_mirror BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_settings DROP COLUMN port_mirror');
    }
}
