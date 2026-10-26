<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Erpify\Shared\Kernel\Domain\ValueObject\NormalizedText;

/**
 * Adds `bank_account.holder_name_normalized`, the accent- and case-folded twin of `holder_name` that the
 * `holderName` CONTAINS filter matches, and the GIN trigram index that serves it (`pg_trgm`): a B-tree cannot
 * answer `LIKE '%term%'`, so without it every such filter is a sequential scan.
 *
 * The fold is ICU transliteration ({@see NormalizedText::normalize()}), which SQL cannot reproduce — the
 * `unaccent` extension diverges from it on non-Latin scripts and is not IMMUTABLE — so the existing rows are
 * backfilled in PHP, in `postUp()`, inside this migration's transaction. Through the connection rather than
 * `addSql()` so the holders' names never appear in the migration's SQL output.
 *
 * The column keeps `DEFAULT ''`: an image predating it inserts without naming it, and `NOT NULL` with no
 * default would refuse that insert (`MigrationColumnDefaultGateTest`). The filter does not match such a row
 * until an edit changes its holder.
 *
 * DBAL models neither the access method nor the operator class of an index, so the mapping declares the
 * index by name and columns only and `TrigramIndexSchemaFunctionalTest` pins what this migration builds.
 *
 * `down()` drops the index and the column, then the extension if nothing else uses it.
 */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bank_account.holder_name_normalized with a pg_trgm GIN index for accent-insensitive search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql("ALTER TABLE bank_account ADD COLUMN IF NOT EXISTS holder_name_normalized TEXT DEFAULT '' NOT NULL");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_bank_account_holder_name_trgm ON bank_account USING gin (holder_name_normalized gin_trgm_ops)');
    }

    public function postUp(Schema $schema): void
    {
        // A dry run executes none of up()'s SQL and still calls postUp(): without the column there is nothing
        // to fill, and an UPDATE naming it would fail the dry run.
        $columnExists = $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() '
            . "AND table_name = 'bank_account' AND column_name = 'holder_name_normalized'",
        );

        if (false === $columnExists) {
            return;
        }

        foreach ($this->connection->fetchAllAssociative('SELECT id, holder_name FROM bank_account') as $row) {
            $id = $row['id'] ?? null;
            $holderName = $row['holder_name'] ?? null;

            if (!\is_string($id) || !\is_string($holderName)) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE bank_account SET holder_name_normalized = :normalized WHERE id = :id',
                ['normalized' => NormalizedText::normalize($holderName), 'id' => $id],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_bank_account_holder_name_trgm');
        $this->addSql('ALTER TABLE bank_account DROP COLUMN IF EXISTS holder_name_normalized');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm RESTRICT');
    }
}
