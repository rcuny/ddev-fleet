<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-300: `template.database` (the instance has a database of its own) and `template.agent` (an AI agent works in it).
 * Existing templates keep today's behaviour: a database, no agent warning.
 */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template.database and template.agent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template ADD COLUMN "database" BOOLEAN DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE template ADD COLUMN agent BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template DROP COLUMN "database"');
        $this->addSql('ALTER TABLE template DROP COLUMN agent');
    }
}
