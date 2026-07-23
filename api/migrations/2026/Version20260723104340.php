<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Destructive by design: the image surface is being removed, not archived, so the bytes go with it.
 * Reversible in STRUCTURE only — down() restores the table, columns, foreign key and indexes, never
 * the rows.
 */
final class Version20260723104340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the media table and the bank logo / stored-object columns';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media') > 0,
            'The media table holds rows. Export them before applying this migration: it destroys the bytes.',
        );

        $this->addSql('ALTER TABLE bank DROP CONSTRAINT IF EXISTS fk_d860bf7abaae86a3');
        $this->addSql('DROP INDEX IF EXISTS idx_d860bf7abaae86a3');
        $this->addSql('ALTER TABLE bank DROP COLUMN IF EXISTS stored_object_key');
        $this->addSql('ALTER TABLE bank DROP COLUMN IF EXISTS stored_object_mime_type');
        $this->addSql('ALTER TABLE bank DROP COLUMN IF EXISTS stored_object_byte_size');
        $this->addSql('ALTER TABLE bank DROP COLUMN IF EXISTS stored_object_content_hash');
        $this->addSql('ALTER TABLE bank DROP COLUMN IF EXISTS logo_media_id');
        $this->addSql('DROP TABLE IF EXISTS media');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE media (content_hash VARCHAR(64) NOT NULL, mime_type VARCHAR(64) NOT NULL, byte_size INT NOT NULL, raw_bytes BYTEA NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX media_content_hash_uniq ON media (content_hash)');
        $this->addSql('ALTER TABLE bank ADD stored_object_key VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD stored_object_mime_type VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD stored_object_byte_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD stored_object_content_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD logo_media_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD CONSTRAINT fk_d860bf7abaae86a3 FOREIGN KEY (logo_media_id) REFERENCES media (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_d860bf7abaae86a3 ON bank (logo_media_id)');
    }
}
