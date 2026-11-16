<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-344: the template option that exposes an instance's Mailpit at mailpit--<instance>.<domain> (off by default).
 */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template.mailpit (default false).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template ADD COLUMN mailpit BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template DROP COLUMN mailpit');
    }
}
