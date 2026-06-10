<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Infrastructure-only evolution for keyset order stability (story 1.2): composite (column, id)
 * indexes on the non-unique temporal sort keys, and a byte-wise `COLLATE "C"` on the sortable text
 * columns. This is index + ordering-determinism plumbing — it changes neither the logical schema, the
 * entity, nor any domain semantics, so it does NOT reopen the "zero migrations" pin of NFR6.
 *
 * The composite indexes are modelled by Doctrine ({@see \Erpify\Backoffice\Bank\Domain\Entity\Bank}
 * `#[ORM\Index]`) so `make db.validate` stays green for them. The per-column collation is NOT modelled
 * by Doctrine, so it lives only here (the schema is the source of truth, AR23/D-4); `db.validate` may
 * report a benign collation drift — accepted and documented, never forced onto the ORM.
 */
final class Version20260610195734 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite (created_at|updated_at, id) indexes and COLLATE "C" on the sortable text '
            . 'columns to back keyset order stability on bank (story 1.2)';
    }

    public function up(Schema $schema): void
    {
        // Composite indexes for the non-unique temporal sort keys; the simple idx_bank_created_at /
        // idx_bank_updated_at indexes are kept (they still back the range filters of story 1.7).
        $this->addSql('CREATE INDEX idx_bank_created_at_id ON bank (created_at, id)');
        $this->addSql('CREATE INDEX idx_bank_updated_at_id ON bank (updated_at, id)');

        // Byte-wise collation so the sortable text columns order deterministically and identically to
        // the keyset oracle, independent of the database's default (locale) collation.
        $this->addSql('ALTER TABLE bank ALTER COLUMN name_normalized TYPE VARCHAR(255) COLLATE "C"');
        $this->addSql('ALTER TABLE bank ALTER COLUMN short_name TYPE VARCHAR(50) COLLATE "C"');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank ALTER COLUMN name_normalized TYPE VARCHAR(255) COLLATE pg_catalog."default"');
        $this->addSql('ALTER TABLE bank ALTER COLUMN short_name TYPE VARCHAR(50) COLLATE pg_catalog."default"');

        // DROP INDEX IF EXISTS so a partially-applied down() is still reversible (consistent with #207).
        $this->addSql('DROP INDEX IF EXISTS idx_bank_created_at_id');
        $this->addSql('DROP INDEX IF EXISTS idx_bank_updated_at_id');
    }
}
