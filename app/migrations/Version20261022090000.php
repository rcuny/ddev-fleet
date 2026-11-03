<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Webhook actions (M0.08b): the Bitbucket `pipeline:write` token, sealed like the HMAC secret and stored on the
 * receiver (all four columns are NULL while no token is set), and the body hash of a delivery, which makes a replayed
 * body a duplicate even when the replay carries a fresh delivery id.
 */
final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the sealed pipeline token to webhook_endpoint and the body hash to webhook_delivery.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_endpoint ADD COLUMN token_ciphertext BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE webhook_endpoint ADD COLUMN token_nonce BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE webhook_endpoint ADD COLUMN token_key_version INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE webhook_endpoint ADD COLUMN token_updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE webhook_delivery ADD COLUMN body_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_webhook_delivery_body ON webhook_delivery (project_name, source, body_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_webhook_delivery_body');
        $this->addSql('ALTER TABLE webhook_delivery DROP COLUMN body_hash');
        $this->addSql('ALTER TABLE webhook_endpoint DROP COLUMN token_updated_at');
        $this->addSql('ALTER TABLE webhook_endpoint DROP COLUMN token_key_version');
        $this->addSql('ALTER TABLE webhook_endpoint DROP COLUMN token_nonce');
        $this->addSql('ALTER TABLE webhook_endpoint DROP COLUMN token_ciphertext');
    }
}
