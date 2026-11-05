<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-199 (capacity allotments, FLE-197): nullable `cpu_threads` and `slots` on project and template, and the host's
 * `cpu_reserve_threads` (default 2) and `max_instances` (default 25). No data changes: null means unlimited or inherited.
 */
final class Version20261105110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project and template cpu_threads and slots, host_settings cpu_reserve_threads and max_instances.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD COLUMN cpu_threads INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD COLUMN slots INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE template ADD COLUMN cpu_threads INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE template ADD COLUMN slots INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE host_settings ADD COLUMN cpu_reserve_threads SMALLINT DEFAULT 2 NOT NULL');
        $this->addSql('ALTER TABLE host_settings ADD COLUMN max_instances INTEGER DEFAULT 25 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_settings DROP COLUMN max_instances');
        $this->addSql('ALTER TABLE host_settings DROP COLUMN cpu_reserve_threads');
        $this->addSql('ALTER TABLE template DROP COLUMN slots');
        $this->addSql('ALTER TABLE template DROP COLUMN cpu_threads');
        $this->addSql('ALTER TABLE project DROP COLUMN slots');
        $this->addSql('ALTER TABLE project DROP COLUMN cpu_threads');
    }
}
