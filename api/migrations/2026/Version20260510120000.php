<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Consolidated bank schema. Replaces the prior four bank-only migrations
 * plus the case/accent-insensitive uniqueness work — all collapsed into a
 * single CREATE TABLE that reflects the entity's final shape.
 *
 * The seed data that previously lived in the schema migration moved to
 * Hautelook fixtures (`tests/DataFixtures/Fixtures/Bank.yaml`) so prod
 * never receives placeholder rows and dev/test get a richer dataset.
 *
 * Uniqueness rules:
 *   - `name`           — preserves the user's casing for display; uniqueness
 *                        is enforced through `name_normalized`, populated
 *                        via `NormalizedText::from()` (lower + trim + ICU
 *                        Latin-ASCII), so "BBVA" / "bbva" and "Sociedad
 *                        Anónima" / "Sociedad Anonima" collide.
 *   - `short_name`     — stored canonicalized (upper-case ASCII, no
 *                        diacritics) via `NormalizedText::toAsciiUpper()`,
 *                        with the unique index sitting directly on the
 *                        column. No separate normalized half is needed.
 */
final class Version20260510120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bank table with case- and accent-insensitive uniqueness';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE bank ('
            . 'id UUID NOT NULL, '
            . 'name VARCHAR(255) NOT NULL, '
            . 'name_normalized VARCHAR(255) NOT NULL, '
            . 'short_name VARCHAR(50) NOT NULL, '
            . 'logo_media_id UUID DEFAULT NULL, '
            . 'stored_object_key VARCHAR(512) DEFAULT NULL, '
            . 'stored_object_mime_type VARCHAR(64) DEFAULT NULL, '
            . 'stored_object_byte_size INT DEFAULT NULL, '
            . 'stored_object_content_hash VARCHAR(64) DEFAULT NULL, '
            . 'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'PRIMARY KEY (id))',
        );

        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_name_normalized ON bank (name_normalized)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_short_name ON bank (short_name)');
        $this->addSql('CREATE INDEX IDX_D860BF7ABAAE86A3 ON bank (logo_media_id)');

        $this->addSql(
            'ALTER TABLE bank ADD CONSTRAINT FK_D860BF7ABAAE86A3 '
            . 'FOREIGN KEY (logo_media_id) REFERENCES media (id) NOT DEFERRABLE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank DROP CONSTRAINT FK_D860BF7ABAAE86A3');
        $this->addSql('DROP TABLE bank');
    }
}
