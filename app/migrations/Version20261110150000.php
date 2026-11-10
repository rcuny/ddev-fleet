<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-266: `ssh_extra_key` (the public half of the extra SSH keys Fleet manages) and `template.ssh_identities` (push key and
 * extras, or extras only). Every existing template keeps today's behaviour: push key and extras.
 */
final class Version20261110150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ssh_extra_key and template.ssh_identities.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ssh_extra_key (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(32) NOT NULL, source VARCHAR(16) NOT NULL, public_key CLOB NOT NULL, fingerprint VARCHAR(128) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ssh_extra_key_name ON ssh_extra_key (name)');
        $this->addSql("ALTER TABLE template ADD COLUMN ssh_identities VARCHAR(16) DEFAULT 'push_and_extras' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ssh_extra_key');
        $this->addSql('ALTER TABLE template DROP COLUMN ssh_identities');
    }
}
