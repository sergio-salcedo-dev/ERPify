<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes Postgres enforce the closed vocabularies of `audit_log`, which until now only PHP enforced.
 *
 * `ActorContext` already makes an illegal actor unrepresentable in the application, and `AuditLevel` an
 * unknown level, but raw SQL — DBAL, fixtures, acceptance steps — writes this table without either. A `user`
 * row with a NULL `actor_id` is the dangerous one: both erasure passes match by id, so neither ever reaches it
 * and a person's request metadata survives the erasure. A `level` outside the enum is the other: the pruner
 * deletes `WHERE level = :level` once per `AuditLevel` case, so such a row outlives every retention window.
 * Three named constraints close them:
 *
 * - `audit_log_actor_type_check` — `actor_type` is one of the four lowercase tokens of `ActorType`.
 * - `audit_log_actor_id_presence_check` — `anonymous`/`system` carry no id, `api_key`/`user` always carry
 *   one. Written as a boolean equality rather than an implication: with `actor_type NOT NULL` both sides
 *   are always TRUE or FALSE, so the expression never evaluates to NULL, which a CHECK would accept.
 * - `audit_log_level_check` — `level` is one of the three lowercase tokens of `AuditLevel`.
 *
 * The token lists are literals on purpose. A migration is immutable and the code is not, so it imports
 * nothing from `src`; `AuditLogCheckConstraintFunctionalTest` is what fails when `ActorType` or `AuditLevel`
 * gains a case these constraints do not admit. DBAL neither models nor introspects a table-level CHECK, so
 * `make db.diff` never sees these three in either direction — that test is their only guardian.
 *
 * A row already violating any of them would make the `ALTER TABLE` fail with a bare 23514 naming neither the
 * row nor the remedy, so `up()` counts them first and aborts with the query that lists them. That pre-flight
 * is preferred over `NOT VALID` + `VALIDATE CONSTRAINT`: the split shortens the lock but its `VALIDATE` fails
 * just as mutely, and stopping at `NOT VALID` would leave the very rows these constraints exist for in place,
 * unchecked for ever. Such a row is repaired by hand, deliberately — deleting it may destroy audit evidence
 * and rewriting it invents an actor or a level, and neither is a call a migration may make on its own.
 *
 * Cost: one `ALTER TABLE` validates the three constraints in a single scan of `audit_log` while holding an
 * `ACCESS EXCLUSIVE` lock, blocking every audit write — and the `change` tier writes inside the business
 * transaction. Negligible on the pre-production table this ships against; whoever applies it over a large
 * `audit_log` should split it into `ADD CONSTRAINT … NOT VALID` and a separate `VALIDATE CONSTRAINT`, after
 * the same pre-flight.
 */
final class Version20260929102251 extends AbstractMigration
{
    private const string ACTOR_TYPE_ADMITTED = "actor_type IN ('anonymous', 'system', 'api_key', 'user')";

    private const string ACTOR_ID_PRESENCE = "(actor_type IN ('anonymous', 'system')) = (actor_id IS NULL)";

    private const string LEVEL_ADMITTED = "level IN ('activity', 'security', 'change')";

    public function getDescription(): string
    {
        return 'Enforce the audit_log closed vocabularies: actor_type, actor_id presence by type, and level';
    }

    public function up(Schema $schema): void
    {
        $violations = \sprintf(
            'NOT (%s AND %s AND %s)',
            self::ACTOR_TYPE_ADMITTED,
            self::ACTOR_ID_PRESENCE,
            self::LEVEL_ADMITTED,
        );
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE ' . $violations);
        $violating = \is_numeric($count) ? (int) $count : 0;

        $this->abortIf(
            $violating > 0,
            \sprintf(
                '%d audit_log row(s) violate the CHECK constraints this migration adds. List them with '
                . '"SELECT id, level, actor_type, actor_id, occurred_on FROM audit_log WHERE %s" and repair '
                . 'or remove each one by hand before applying it.',
                $violating,
                $violations,
            ),
        );

        $this->addSql(
            'ALTER TABLE audit_log '
            . 'ADD CONSTRAINT audit_log_actor_type_check CHECK (' . self::ACTOR_TYPE_ADMITTED . '), '
            . 'ADD CONSTRAINT audit_log_actor_id_presence_check CHECK (' . self::ACTOR_ID_PRESENCE . '), '
            . 'ADD CONSTRAINT audit_log_level_check CHECK (' . self::LEVEL_ADMITTED . ')',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_level_check');
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_actor_id_presence_check');
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_actor_type_check');
    }
}
