<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-286: the per-project opt-in for terminal input over the API (`project.allow_api_terminal`, off) and `api_call`, the
 * evidence table of those calls (token, pane, foreground command, length, sha256 and the text up to 4 KiB).
 */
final class Version20261111100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project.allow_api_terminal and api_call.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD COLUMN allow_api_terminal BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE api_call (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, token_id INTEGER NOT NULL, actor VARCHAR(64) NOT NULL, route VARCHAR(64) NOT NULL, instance VARCHAR(64) NOT NULL, pane VARCHAR(8) NOT NULL, foreground VARCHAR(64) NOT NULL, client_ip VARCHAR(45) NOT NULL, length INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, text CLOB NOT NULL, enter BOOLEAN NOT NULL, outcome VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_api_call_token ON api_call (token_id, created_at)');
        $this->addSql('CREATE INDEX idx_api_call_created ON api_call (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_call');
        $this->addSql('ALTER TABLE project DROP COLUMN allow_api_terminal');
    }
}
