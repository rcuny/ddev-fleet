<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds the Typesense catalogue port (FLE-92): public 9108, router 8108, as v0 had it. INSERT OR IGNORE skips the row
 * silently when a port with that name or one of those numbers already exists, so a host that created its own entry
 * keeps it. `Project.typesense` and the `typesense` port assignment are kept in step by ProjectService.
 */
final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the typesense catalogue port (9108 -> 8108) if absent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT OR IGNORE INTO named_port (name, public_port, router_port, created_at) VALUES ('typesense', 9108, 8108, strftime('%Y-%m-%d %H:%M:%S', 'now'))");
    }

    public function down(Schema $schema): void
    {
        // A port that is assigned to a project (RESTRICT) stays: the foreign key makes the delete fail loudly.
        $this->addSql("DELETE FROM named_port WHERE name = 'typesense' AND public_port = 9108 AND router_port = 8108");
    }
}
