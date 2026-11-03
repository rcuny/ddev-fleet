<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Site-only users (FLE-101): accounts that only open the instance sites of their projects. Existing users are regular.
 */
final class Version20261024090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fleet_user.site_only.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fleet_user ADD COLUMN site_only BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fleet_user DROP COLUMN site_only');
    }
}
