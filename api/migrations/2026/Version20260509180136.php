<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enforces uniqueness on bank.name and bank.short_name to align with the
 * `#[UniqueEntity]` constraints declared on Erpify\Backoffice\Bank\Domain\Entity\Bank.
 *
 * Idempotent against pre-existing duplicates: if the dev DB accumulated rows
 * with colliding names from earlier work (e.g. PHPUnit functional fixtures
 * left in the dev volume), the up() migration de-duplicates first by keeping
 * the oldest row (smallest `created_at`, ties broken by smallest `id`) and
 * deleting the rest. Without this step, `CREATE UNIQUE INDEX` would fail on
 * any DB with duplicates and block the migration entirely.
 */
final class Version20260509180136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce uniqueness on bank.name and bank.short_name to align with #[UniqueEntity].';
    }

    public function up(Schema $schema): void
    {
        // Drop duplicate rows before constraining — keeps the oldest row per
        // (name) and (short_name) collision; deletes the newer copies.
        // Window-function ROW_NUMBER over (PARTITION BY name ORDER BY created_at, id)
        // gives the head of every group rank=1; everything else is collateral.
        $this->addSql(
            'DELETE FROM bank '
            . 'WHERE id IN ('
            . '    SELECT id FROM ('
            . '        SELECT id, ROW_NUMBER() OVER ('
            . '            PARTITION BY name ORDER BY created_at ASC, id ASC'
            . '        ) AS rn FROM bank'
            . '    ) ranked WHERE rn > 1'
            . ')',
        );
        $this->addSql(
            'DELETE FROM bank '
            . 'WHERE id IN ('
            . '    SELECT id FROM ('
            . '        SELECT id, ROW_NUMBER() OVER ('
            . '            PARTITION BY short_name ORDER BY created_at ASC, id ASC'
            . '        ) AS rn FROM bank'
            . '    ) ranked WHERE rn > 1'
            . ')',
        );

        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_name ON bank (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_short_name ON bank (short_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_bank_name');
        $this->addSql('DROP INDEX UNIQ_bank_short_name');
    }
}
