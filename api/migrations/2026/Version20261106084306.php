<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Documents ingest: the `upload_intent` record every sealed byte is preceded by, and the two `document` columns
 * the promotion and the scan sweep read.
 *
 * `uniq_upload_intent_key_hash` is what the declare step's `INSERT … ON CONFLICT (key_hash) DO NOTHING` names, so
 * a replay never raises a unique violation; it is nullable, so every expired intent (its hash nulled) and every
 * upload without a key coexist. `uniq_upload_intent_scope_id` keeps two uploads from sharing a scope.
 * `next_scan_at` is filed by the application — the upload commit's transaction time on the database clock — and
 * keeps a literal default only for an older image's insert.
 *
 * DBAL does not introspect CHECK constraints, so this migration owns them: the closed state vocabulary, the size
 * bound (`UploadLimits::MAX_ORIGINAL_BYTES`), the digest shapes, and the shape of each state (a claimed intent has
 * its lease and deadline, a committed one its document).
 */
final class Version20261106084306 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create upload_intent and add promoted_at / next_scan_at to document (Documents ingest).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE upload_intent (id UUID NOT NULL, actor_id UUID NOT NULL, scope_id UUID NOT NULL, document_id UUID DEFAULT NULL, predecessor_id UUID DEFAULT NULL, state VARCHAR(16) NOT NULL, declared_size INT NOT NULL, declared_sha256 VARCHAR(64) DEFAULT NULL, key_hash VARCHAR(64) DEFAULT NULL, retention_reason VARCHAR(32) NOT NULL, legal_basis VARCHAR(32) NOT NULL, review_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, ttl_expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, claimed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, lease_expires_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, upload_deadline_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, committed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_upload_intent_actor_id ON upload_intent (actor_id)');
        $this->addSql('CREATE INDEX idx_upload_intent_state ON upload_intent (state)');
        $this->addSql('CREATE UNIQUE INDEX uniq_upload_intent_scope_id ON upload_intent (scope_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_upload_intent_key_hash ON upload_intent (key_hash)');
        $this->addSql('ALTER TABLE document ADD promoted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        // A literal default, the epoch, so an image that predates the column can still insert a row (due for a
        // scan at once); every writer in this tree names the column itself.
        $this->addSql(
            "ALTER TABLE document ADD next_scan_at TIMESTAMP(6) WITH TIME ZONE DEFAULT '1970-01-01 00:00:00+00' NOT NULL",
        );

        $this->addSql(
            'ALTER TABLE upload_intent ADD CONSTRAINT upload_intent_state_check CHECK ('
            . "state IN ('declared', 'streaming', 'committed', 'expired'))",
        );
        $this->addSql(
            'ALTER TABLE upload_intent ADD CONSTRAINT upload_intent_declared_size_check '
            . 'CHECK (declared_size BETWEEN 1 AND 104857600)',
        );
        $this->addSql(
            'ALTER TABLE upload_intent ADD CONSTRAINT upload_intent_declared_sha256_check CHECK ('
            . "declared_sha256 IS NULL OR declared_sha256 ~ '^[0-9a-f]{64}$')",
        );
        $this->addSql(
            'ALTER TABLE upload_intent ADD CONSTRAINT upload_intent_key_hash_check CHECK ('
            . "key_hash IS NULL OR key_hash ~ '^[0-9a-f]{64}$')",
        );
        $this->addSql(
            'ALTER TABLE upload_intent ADD CONSTRAINT upload_intent_streaming_shape_check CHECK ('
            . "state <> 'streaming' OR (claimed_at IS NOT NULL AND lease_expires_at IS NOT NULL "
            . 'AND upload_deadline_at IS NOT NULL))',
        );
        $this->addSql(
            'ALTER TABLE upload_intent ADD CONSTRAINT upload_intent_committed_shape_check CHECK ('
            . "state <> 'committed' OR (claimed_at IS NOT NULL AND document_id IS NOT NULL AND committed_at IS NOT NULL))",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE upload_intent');
        $this->addSql('ALTER TABLE document DROP promoted_at');
        $this->addSql('ALTER TABLE document DROP next_scan_at');
    }
}
