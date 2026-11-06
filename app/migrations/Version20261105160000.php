<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-216: `named_port.access` ('gated' or 'public'). Typesense was served without Authelia in v0 and the browser calls
 * it cross-origin (the preflight carries no cookie), so the existing `typesense` port becomes public. Every other port
 * stays gated. The Caddy sites follow with `fleet:port:refresh`.
 */
final class Version20261105160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add named_port.access (default 'gated'); the typesense port becomes public.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE named_port ADD COLUMN access VARCHAR(8) DEFAULT 'gated' NOT NULL");
        $this->addSql("UPDATE named_port SET access = 'public' WHERE name = 'typesense'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE named_port DROP COLUMN access');
    }
}
