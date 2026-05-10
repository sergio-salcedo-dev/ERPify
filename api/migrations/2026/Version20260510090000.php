<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces the case-sensitive uniqueness on `bank.name` / `bank.short_name`
 * with case-insensitive uniqueness driven by two new normalized columns
 * (`name_normalized`, `short_name_normalized`) populated by
 * `Erpify\Shared\Domain\ValueObject\NormalizedText`.
 *
 * The display columns keep the user's original casing; only the normalized
 * columns carry the unique constraint, so "BBVA" and "bbva" now collide while
 * "BBVA" still renders as "BBVA" in API responses.
 *
 * Idempotent against existing duplicates: rows whose normalized form collides
 * are de-duplicated keeping the oldest (smallest `created_at`, ties broken by
 * smallest `id`) before the unique index is created.
 */
final class Version20260510090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bank.name_normalized / bank.short_name_normalized for case-insensitive uniqueness.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank ADD COLUMN name_normalized VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD COLUMN short_name_normalized VARCHAR(50) DEFAULT NULL');

        $this->addSql('UPDATE bank SET name_normalized = LOWER(TRIM(name)), short_name_normalized = LOWER(TRIM(short_name))');

        // Drop duplicate rows whose normalized name (or short_name) collides
        // before constraining; keep the oldest row per group, delete the rest.
        $this->addSql(
            'DELETE FROM bank '
            . 'WHERE id IN ('
            . '    SELECT id FROM ('
            . '        SELECT id, ROW_NUMBER() OVER ('
            . '            PARTITION BY name_normalized ORDER BY created_at ASC, id ASC'
            . '        ) AS rn FROM bank'
            . '    ) ranked WHERE rn > 1'
            . ')',
        );
        $this->addSql(
            'DELETE FROM bank '
            . 'WHERE id IN ('
            . '    SELECT id FROM ('
            . '        SELECT id, ROW_NUMBER() OVER ('
            . '            PARTITION BY short_name_normalized ORDER BY created_at ASC, id ASC'
            . '        ) AS rn FROM bank'
            . '    ) ranked WHERE rn > 1'
            . ')',
        );

        $this->addSql('ALTER TABLE bank ALTER COLUMN name_normalized SET NOT NULL');
        $this->addSql('ALTER TABLE bank ALTER COLUMN short_name_normalized SET NOT NULL');

        $this->addSql('DROP INDEX UNIQ_bank_name');
        $this->addSql('DROP INDEX UNIQ_bank_short_name');

        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_name_normalized ON bank (name_normalized)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_short_name_normalized ON bank (short_name_normalized)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_bank_name_normalized');
        $this->addSql('DROP INDEX UNIQ_bank_short_name_normalized');

        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_name ON bank (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_short_name ON bank (short_name)');

        $this->addSql('ALTER TABLE bank DROP COLUMN name_normalized');
        $this->addSql('ALTER TABLE bank DROP COLUMN short_name_normalized');
    }
}
