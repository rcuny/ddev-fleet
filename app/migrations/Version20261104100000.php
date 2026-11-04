<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-190: `template.ssh_keys`, the names of extra SSH keys a template's instances get in their own ssh-agent. Empty for
 * every existing template.
 */
final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template.ssh_keys (JSON list, default empty).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE template ADD COLUMN ssh_keys CLOB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template DROP COLUMN ssh_keys');
    }
}
