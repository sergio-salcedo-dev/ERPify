<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Documents context: the `document` snapshot and its append-only `document_custody` log.
 *
 * Both unique constraints that a violation is classified by are named (`uniq_document_custody_document_seq`,
 * `uniq_document_idempotency_key_hash`), because the application maps a unique violation by NAME: the
 * sequence one is a lost race and is retried, the idempotency one is a conflict and is not. `scope_id` carries
 * its own `UNIQUE`, since two documents sharing an encryption scope would let one's erasure shred the other's
 * bytes. The custody foreign key has no cascade (NO ACTION) — a document is never physically deleted — and the
 * actor index is what the person-erasure rewrite and the detective source seek through.
 *
 * DBAL does not introspect CHECK constraints, so `doctrine:schema:validate` stays silent about them and this
 * migration owns them; the functional schema test proves each refuses what it states. `retention_reason` and
 * `legal_basis` carry none, and neither does `document_custody.event`: the stories that define their values add
 * them without a migration.
 */
final class Version20261105202636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create document and document_custody (state-oriented snapshot plus append-only custody log).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document (scope_id UUID NOT NULL, series_id UUID NOT NULL, predecessor_id UUID DEFAULT NULL, uploader_id UUID NOT NULL, state VARCHAR(16) NOT NULL, digest VARCHAR(64) NOT NULL, size_bytes INT NOT NULL, idempotency_key_hash VARCHAR(64) DEFAULT NULL, filename_ciphertext TEXT DEFAULT NULL, retention_reason VARCHAR(32) DEFAULT NULL, legal_basis VARCHAR(32) DEFAULT NULL, review_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_document_uploader_id ON document (uploader_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_document_scope_id ON document (scope_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_document_idempotency_key_hash ON document (idempotency_key_hash)');
        $this->addSql('CREATE TABLE document_custody (id UUID NOT NULL, document_id UUID NOT NULL, seq INT NOT NULL, event VARCHAR(32) NOT NULL, actor_id UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_document_custody_actor_id ON document_custody (actor_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_document_custody_document_seq ON document_custody (document_id, seq)');
        $this->addSql('CREATE INDEX IDX_E95EF55AC33F7837 ON document_custody (document_id)');
        $this->addSql('ALTER TABLE document_custody ADD CONSTRAINT fk_document_custody_document FOREIGN KEY (document_id) REFERENCES document (id)');

        $this->addSql(
            'ALTER TABLE document ADD CONSTRAINT document_state_check CHECK ('
            . "state IN ('received', 'scanning', 'ready', 'quarantined', 'scan_failed', 'erased'))",
        );
        $this->addSql("ALTER TABLE document ADD CONSTRAINT document_digest_check CHECK (digest ~ '^[0-9a-f]{64}$')");
        $this->addSql('ALTER TABLE document ADD CONSTRAINT document_size_bytes_check CHECK (size_bytes > 0)');
        $this->addSql(
            'ALTER TABLE document ADD CONSTRAINT document_idempotency_key_hash_check CHECK ('
            . "idempotency_key_hash IS NULL OR idempotency_key_hash ~ '^[0-9a-f]{64}$')",
        );
        $this->addSql('ALTER TABLE document_custody ADD CONSTRAINT document_custody_seq_check CHECK (seq >= 1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_custody DROP CONSTRAINT fk_document_custody_document');
        $this->addSql('DROP TABLE document_custody');
        $this->addSql('DROP TABLE document');
    }
}
