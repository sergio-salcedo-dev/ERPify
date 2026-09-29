<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedTableSchemaListener;

/**
 * Injects the permanent, append-only `audit_log` table into Doctrine's in-memory schema. The table is
 * written through plain DBAL ({@see DbalAuditLogWriter}) and has no ORM entity, so without this listener
 * `make db.diff` would generate a DROP for it. This listener is the **source of truth** for the table's
 * columns and indexes — the migrations that create and alter them are generated from it. It is not the
 * source of truth for the table's `CHECK` constraints, which it cannot express (below).
 *
 * Doctrine's schema abstraction expresses only a subset of the ideal DDL: there is no native Postgres `ENUM`,
 * so `level`/`actor_type` are plain `VARCHAR` holding the backing value of their PHP enum. `ip` is
 * `VARCHAR(45)` because DBAL models no `inet` type and no query needs subnet operators.
 *
 * Nor does it model a table-level `CHECK`, and its schema manager does not introspect one, yet the table
 * carries three, because raw SQL writes it without `ActorContext` or `AuditLevel`: the actor discriminant
 * ({@see self::ACTOR_TYPE_CHECK}, {@see self::ACTOR_ID_PRESENCE_CHECK}) — a `user` row with a NULL `actor_id`
 * is one neither erasure pass can reach — and the level ({@see self::LEVEL_CHECK}) — a token outside the enum
 * is one no pass of the pruner deletes. Their definitions live only in the migration that adds them, so
 * `make db.diff` never sees them in either direction. The constants below are nothing but their shared
 * names, read by `AuditLogCheckConstraintFunctionalTest` — their guardian, including against `ActorType` or
 * `AuditLevel` gaining a case the token checks do not admit; this listener applies none of them.
 *
 * Column `DEFAULT` it does express, and the two erasure flags carry one. **It cannot mask a forgotten
 * erasure**, which is the objection this shape usually deserves and does not here: both flags are raised by
 * an `UPDATE` in the erasure passes, never by an `INSERT`, and a column default only applies to an `INSERT`
 * that omits the column. That is exactly why `identity_user.status` is the opposite call — the aggregate
 * sets *that* one on insert, so a default there would swallow a write that forgot it.
 *
 * The writer still supplies every value: a default is not how a row gets written, it is what a row written
 * by the *previous image* gets. A
 * `NOT NULL` column with no default breaks every `INSERT` issued by code that predates the column, and
 * redeploying the previous image tag — the documented rollback in `docs/deployment-guide.md` — does not undo
 * the migration with it, because `down()` never runs. On this table that is not a degraded
 * trail: the `change` tier writes inside `onFlush` with no `catch`, so the business write fails with it.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class AuditLogSchemaListener extends InjectedTableSchemaListener
{
    /** `actor_type` is one of the backing values of `ActorType`. */
    public const string ACTOR_TYPE_CHECK = 'audit_log_actor_type_check';

    /** `anonymous`/`system` rows carry no `actor_id`; `api_key`/`user` rows always carry one. */
    public const string ACTOR_ID_PRESENCE_CHECK = 'audit_log_actor_id_presence_check';

    /** `level` is one of the backing values of `AuditLevel`. */
    public const string LEVEL_CHECK = 'audit_log_level_check';

    public function __construct()
    {
        parent::__construct('audit_log');
    }

    protected function define(TableEditor $table): TableEditor
    {
        return $table
            ->setColumns(
                $this->column('id', Types::GUID)->create(),
                $this->column('level', Types::STRING)->setLength(16)->create(),
                $this->column('action', Types::STRING)->setLength(100)->create(),
                $this->column('actor_type', Types::STRING)->setLength(16)->create(),
                $this->column('actor_id', Types::GUID)->setNotNull(false)->create(),
                $this->column('correlation_id', Types::GUID)->create(),
                $this->column('resource_type', Types::STRING)->setLength(100)->setNotNull(false)->create(),
                $this->column('resource_id', Types::GUID)->setNotNull(false)->create(),
                $this->column('metadata', Types::JSONB)->create(),
                $this->column('ip', Types::STRING)->setLength(45)->setNotNull(false)->create(),
                $this->column('user_agent', Types::STRING)->setLength(512)->setNotNull(false)->create(),
                $this->column('actor_erased', Types::BOOLEAN)->setDefaultValue(false)->create(),
                $this->column('resource_erased', Types::BOOLEAN)->setDefaultValue(false)->create(),
                $this->column('occurred_on', Types::DATETIMETZ_IMMUTABLE)->create(),
                $this->column('encryption_scope_id', Types::STRING)->setLength(160)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
            )
            ->setIndexes(
                $this->index('audit_log_actor_idx', 'actor_id', 'occurred_on')->create(),
                $this->index('audit_log_correlation_idx', 'correlation_id')->create(),
                $this->index('audit_log_level_idx', 'level', 'occurred_on')->create(),
                $this->index('audit_log_resource_idx', 'resource_type', 'resource_id')->create(),
                // Read-side (investigation) indexes: a btree scans both directions, so (occurred_on, id)
                // backs the keyset timeline order (and its `id` tie-break) for ASC and DESC, and
                // (actor_type, occurred_on) backs filtering a timeline by actor kind without a full scan.
                $this->index('audit_log_timeline_idx', 'occurred_on', 'id')->create(),
                $this->index('audit_log_actor_type_idx', 'actor_type', 'occurred_on')->create(),
            )
        ;
    }
}
