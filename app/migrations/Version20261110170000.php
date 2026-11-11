<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLE-273: `api_token`, the service tokens of the HTTP API (only the SHA-256 of a token is stored).
 */
final class Version20261110170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_token.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_token (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(32) NOT NULL, description VARCHAR(255) NOT NULL, token_hash VARCHAR(64) NOT NULL, prefix_hint VARCHAR(8) NOT NULL, scopes CLOB NOT NULL, projects CLOB NOT NULL, instance_globs CLOB NOT NULL, ip_allow CLOB NOT NULL, created_by VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, last_used_ip VARCHAR(45) DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, rotated_from INTEGER DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_api_token_hash ON api_token (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_token');
    }
}
