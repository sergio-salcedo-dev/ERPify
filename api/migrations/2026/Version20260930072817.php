<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes Postgres enforce the closed vocabulary of `audit_log.level`, which until now only `AuditLevel`
 * enforced. Raw SQL — DBAL, fixtures, acceptance steps — writes this table without the enum, and a `level`
 * outside it is a row no retention pass ever reaches: the pruner deletes `WHERE level = :level` once per
 * `AuditLevel` case, so such a row outlives every window. One named constraint closes it:
 * `audit_log_level_check`, `level` is one of the three lowercase tokens of `AuditLevel`.
 *
 * It is a migration of its own rather than a third constraint in `Version20260929102251`, because a database
 * that has already recorded that version would never receive it.
 *
 * The token list is a literal on purpose. A migration is immutable and the code is not, so it imports
 * nothing from `src`; `AuditLogCheckEnumTokenGateTest` compares the literal with `AuditLevel::cases()`
 * without a database, and `AuditLogCheckConstraintFunctionalTest` asks Postgres for what it actually
 * enforces. DBAL neither models nor introspects a table-level CHECK, so `make db.diff` never sees it.
 *
 * A row already violating it would make the `ALTER TABLE` fail with a bare 23514 naming neither the row nor
 * the remedy, so `up()` counts them first and aborts with the query that lists them, for the reasons
 * `Version20260929102251` gives for its own pre-flight. Such a row is repaired by hand: rewriting its level
 * would invent a retention class, and deleting it may destroy audit evidence.
 *
 * A database may already carry a constraint of this name (a pre-release revision of the previous migration
 * added it there), and Postgres has no `ADD CONSTRAINT IF NOT EXISTS`, so `up()` adds it only when absent.
 *
 * Cost: the `ALTER TABLE` scans `audit_log` once under an `ACCESS EXCLUSIVE` lock, blocking every audit
 * write. Negligible on the pre-production table this ships against; over a large `audit_log` split it into
 * `ADD CONSTRAINT … NOT VALID` and a separate `VALIDATE CONSTRAINT`, after the same pre-flight.
 */
final class Version20260930072817 extends AbstractMigration
{
    private const string LEVEL_ADMITTED = "level IN ('activity', 'security', 'change')";

    public function getDescription(): string
    {
        return 'Enforce the audit_log level vocabulary';
    }

    public function up(Schema $schema): void
    {
        $violations = \sprintf('NOT (%s)', self::LEVEL_ADMITTED);
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE ' . $violations);

        $this->abortIf(
            !\is_numeric($count),
            \sprintf('Counting the audit_log rows that violate %s returned no number.', $violations),
        );

        $violating = (int) $count;

        $this->abortIf(
            $violating > 0,
            \sprintf(
                '%d audit_log row(s) violate the CHECK constraint this migration adds. List them with '
                . '"SELECT id, level, action, occurred_on FROM audit_log WHERE %s" and repair or remove each '
                . 'one by hand before applying it.',
                $violating,
                $violations,
            ),
        );

        $present = $this->connection->fetchOne(
            'SELECT 1 FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid '
            . 'JOIN pg_namespace n ON n.oid = t.relnamespace '
            . "WHERE n.nspname = current_schema() AND t.relname = 'audit_log' "
            . "AND c.conname = 'audit_log_level_check'",
        );

        if (false !== $present) {
            return;
        }

        $this->addSql(
            'ALTER TABLE audit_log ADD CONSTRAINT audit_log_level_check CHECK (' . self::LEVEL_ADMITTED . ')',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_level_check');
    }
}
