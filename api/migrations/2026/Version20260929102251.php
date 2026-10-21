<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes Postgres enforce the closed vocabulary of the `audit_log` actor, which until now only PHP enforced.
 *
 * `ActorContext` already makes an illegal actor unrepresentable in the application, but raw SQL — DBAL,
 * fixtures, acceptance steps — writes this table without it. A `user` row with a NULL `actor_id` is the
 * dangerous one: both erasure passes match by id, so neither ever reaches it and a person's request metadata
 * survives the erasure. Two named constraints close it:
 *
 * - `audit_log_actor_type_check` — `actor_type` is one of the four lowercase tokens of `ActorType`.
 * - `audit_log_actor_id_presence_check` — `anonymous`/`system` carry no id, `api_key`/`user` always carry
 *   one. Written as a boolean equality rather than an implication: with `actor_type NOT NULL` both sides
 *   are always TRUE or FALSE, so the expression never evaluates to NULL, which a CHECK would accept.
 *
 * The token lists are literals on purpose. A migration is immutable and the code is not, so it imports
 * nothing from `src`; `AuditLogCheckEnumTokenGateTest` compares these literals with `ActorType::cases()`
 * without a database, and `AuditLogCheckConstraintFunctionalTest` asks Postgres for what it actually
 * enforces. DBAL neither models nor introspects a table-level CHECK, so `make db.diff` never sees these two in
 * either direction — those tests are their only guardians.
 *
 * A row already violating either would make the `ALTER TABLE` fail with a bare 23514 naming neither the row
 * nor the remedy, so `up()` counts them first and aborts with the query that lists them. That pre-flight is
 * preferred over `NOT VALID` + `VALIDATE CONSTRAINT`: the split shortens the lock but its `VALIDATE` fails
 * just as mutely, and stopping at `NOT VALID` would leave the very rows these constraints exist for in place,
 * unchecked for ever. Such a row is repaired by hand, deliberately — deleting it may destroy audit evidence
 * and rewriting it invents an actor, and neither is a call a migration may make on its own.
 *
 * Cost: one `ALTER TABLE` validates both constraints in a single scan of `audit_log` while holding an
 * `ACCESS EXCLUSIVE` lock, blocking every audit write — and the `change` tier writes inside the business
 * transaction. Negligible on the pre-production table this ships against; whoever applies it over a large
 * `audit_log` should split it into `ADD CONSTRAINT … NOT VALID` and a separate `VALIDATE CONSTRAINT`, after
 * the same pre-flight.
 */
final class Version20260929102251 extends AbstractMigration
{
    private const string ACTOR_TYPE_ADMITTED = "actor_type IN ('anonymous', 'system', 'api_key', 'user')";

    private const string ACTOR_ID_PRESENCE = "(actor_type IN ('anonymous', 'system')) = (actor_id IS NULL)";

    public function getDescription(): string
    {
        return 'Enforce the audit_log actor vocabulary: actor_type, and actor_id presence by type';
    }

    public function up(Schema $schema): void
    {
        $violations = \sprintf('NOT (%s AND %s)', self::ACTOR_TYPE_ADMITTED, self::ACTOR_ID_PRESENCE);
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE ' . $violations);

        $this->abortIf(
            !\is_numeric($count),
            \sprintf('Counting the audit_log rows that violate %s returned no number.', $violations),
        );

        $violating = (int) $count;

        $this->abortIf(
            $violating > 0,
            \sprintf(
                '%d audit_log row(s) violate the CHECK constraints this migration adds. List them with '
                . '"SELECT id, actor_type, actor_id, occurred_on FROM audit_log WHERE %s" and repair or '
                . 'remove each one by hand before applying it.',
                $violating,
                $violations,
            ),
        );

        $this->addSql(
            'ALTER TABLE audit_log '
            . 'ADD CONSTRAINT audit_log_actor_type_check CHECK (' . self::ACTOR_TYPE_ADMITTED . '), '
            . 'ADD CONSTRAINT audit_log_actor_id_presence_check CHECK (' . self::ACTOR_ID_PRESENCE . ')',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_actor_id_presence_check');
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_actor_type_check');
    }
}
