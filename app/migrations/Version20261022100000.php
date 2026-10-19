<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schedules (M0.09b): the ticker's heartbeat, one row with the end of the last tick. A tick that finds it more than
 * 90 seconds old (or missing) knows the ticker was down, and labels a late run a catch-up.
 */
final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create scheduler_heartbeat.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scheduler_heartbeat (id SMALLINT NOT NULL, last_tick_at DATETIME NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduler_heartbeat');
    }
}
