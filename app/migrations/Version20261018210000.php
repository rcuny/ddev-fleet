<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Host settings, users, SSH key metadata and encrypted secrets (M0.02).
 */
final class Version20261018210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create host_settings, fleet_user, ssh_key and secret.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fleet_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(64) NOT NULL, display_name VARCHAR(255) NOT NULL, email VARCHAR(254) NOT NULL, password_hash VARCHAR(255) NOT NULL, user_groups CLOB NOT NULL, enabled BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_fleet_user_username ON fleet_user (username)');
        $this->addSql('CREATE TABLE host_settings (id SMALLINT NOT NULL, domain VARCHAR(253) NOT NULL, acme_email VARCHAR(254) DEFAULT NULL, tls_mode VARCHAR(16) NOT NULL, setup_token_hash VARCHAR(64) DEFAULT NULL, setup_token_expires_at DATETIME DEFAULT NULL, setup_completed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE secret (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, scope VARCHAR(32) NOT NULL, scope_id VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL, ciphertext BLOB NOT NULL, nonce BLOB NOT NULL, key_version INTEGER NOT NULL, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_secret_scope_name ON secret (scope, scope_id, name)');
        $this->addSql('CREATE TABLE ssh_key (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, role VARCHAR(16) NOT NULL, public_key CLOB NOT NULL, fingerprint VARCHAR(128) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ssh_key_role ON ssh_key (role)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fleet_user');
        $this->addSql('DROP TABLE host_settings');
        $this->addSql('DROP TABLE secret');
        $this->addSql('DROP TABLE ssh_key');
    }
}
