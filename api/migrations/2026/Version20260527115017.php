<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

 final class Version20260527115017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bank table with case- and accent-insensitive uniqueness';
    }

    public function up(Schema $schema): void
    {
        // 1. Create table
        $this->addSql('CREATE TABLE bank (name VARCHAR(255) NOT NULL, name_normalized VARCHAR(255) NOT NULL, short_name VARCHAR(50) NOT NULL, stored_object_key VARCHAR(512) DEFAULT NULL, stored_object_mime_type VARCHAR(64) DEFAULT NULL, stored_object_byte_size INT DEFAULT NULL, stored_object_content_hash VARCHAR(64) DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, logo_media_id UUID DEFAULT NULL, PRIMARY KEY (id))');

        // 2. Apply COLLATE "C" BEFORE creating unique indices to ensure byte-wise uniqueness and performance
        $this->addSql('ALTER TABLE bank ALTER COLUMN name_normalized TYPE VARCHAR(255) COLLATE "C"');
        $this->addSql('ALTER TABLE bank ALTER COLUMN short_name TYPE VARCHAR(50) COLLATE "C"');

        // 3. Create unique indices (now using "C" collation natively)
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D860BF7AE1B35095 ON bank (name_normalized)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D860BF7A3EE4B093 ON bank (short_name)');

        // 4. FK and its index
        $this->addSql('CREATE INDEX IDX_D860BF7ABAAE86A3 ON bank (logo_media_id)');
        $this->addSql('ALTER TABLE bank ADD CONSTRAINT FK_D860BF7ABAAE86A3 FOREIGN KEY (logo_media_id) REFERENCES media (id) NOT DEFERRABLE');

        // 5. Composite indices for Keyset Pagination (redundant single-column indices omitted)
        $this->addSql('CREATE INDEX idx_bank_created_at_id ON bank (created_at, id)');
        $this->addSql('CREATE INDEX idx_bank_updated_at_id ON bank (updated_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank DROP CONSTRAINT FK_D860BF7ABAAE86A3');
        $this->addSql('DROP TABLE bank');
    }
}
