<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Webhooks (M0.08a): the receivers (one per project and source, with the sealed HMAC secret), their rules, and the
 * delivery log. The delivery log has no foreign keys: it outlives a deleted project. Its unique index on the dedupe id
 * is what turns a retried delivery into a duplicate (NULLs are distinct, so unmatched deliveries never collide).
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create webhook_endpoint, webhook_rule and webhook_delivery.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE webhook_endpoint (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, source VARCHAR(16) NOT NULL, ciphertext BLOB NOT NULL, nonce BLOB NOT NULL, key_version INTEGER NOT NULL, created_at DATETIME NOT NULL, rotated_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_3AB88953166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_webhook_endpoint_project_source ON webhook_endpoint (project_id, source)');
        $this->addSql('CREATE INDEX IDX_3AB88953166D1F9C ON webhook_endpoint (project_id)');
        $this->addSql('CREATE TABLE webhook_rule (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, position INTEGER NOT NULL, "action" VARCHAR(16) NOT NULL, match_conditions CLOB NOT NULL, params CLOB NOT NULL, endpoint_id INTEGER NOT NULL, CONSTRAINT FK_F3F439D721AF7E36 FOREIGN KEY (endpoint_id) REFERENCES webhook_endpoint (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F3F439D721AF7E36 ON webhook_rule (endpoint_id)');
        $this->addSql('CREATE TABLE webhook_delivery (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, received_at DATETIME NOT NULL, project_name VARCHAR(40) NOT NULL, source VARCHAR(16) NOT NULL, event VARCHAR(64) NOT NULL, delivery_id VARCHAR(64) DEFAULT NULL, dedupe_id VARCHAR(64) DEFAULT NULL, result VARCHAR(16) NOT NULL, reason VARCHAR(200) DEFAULT NULL, subject VARCHAR(200) DEFAULT NULL, status_change VARCHAR(200) DEFAULT NULL, rule VARCHAR(200) DEFAULT NULL, "action" VARCHAR(16) DEFAULT NULL, decision VARCHAR(300) DEFAULT NULL, job_id INTEGER DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_webhook_delivery_project ON webhook_delivery (project_name, received_at)');
        $this->addSql('CREATE INDEX idx_webhook_delivery_received ON webhook_delivery (received_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_webhook_delivery_dedupe ON webhook_delivery (project_name, source, dedupe_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_delivery');
        $this->addSql('DROP TABLE webhook_rule');
        $this->addSql('DROP TABLE webhook_endpoint');
    }
}
