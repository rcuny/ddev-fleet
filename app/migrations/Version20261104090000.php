<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-145: `project.container_ssh`, the opt-in for a dedicated ssh-agent (with the push key) per instance, so git over SSH
 * works inside the web container. Off for every existing project.
 */
final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project.container_ssh (default off).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD COLUMN container_ssh BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP COLUMN container_ssh');
    }
}
