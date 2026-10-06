<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Persistence\Infrastructure\InjectedTableSchemaListener;
use LogicException;

/**
 * Injects the permanent, append-only `audit_log` table into Doctrine's in-memory schema. The table is
 * written through plain DBAL ({@see DbalAuditLogWriter}) and has no ORM entity, so without this listener
 * `make db.diff` would generate a DROP for it. This listener is the **source of truth** for the table's
 * shape — the migration is generated from it.
 *
 * Doctrine's schema abstraction expresses only a subset of the ideal DDL: there is no native Postgres
 * `ENUM`, so `level`/`actor_type` are plain `VARCHAR` holding the backing value of their PHP enum. `ip` is
 * `VARCHAR(45)` because DBAL models no `inet` type and no query needs subnet operators.
 *
 * Nor is there a `CHECK`: DBAL neither declares one on a table nor introspects one from the database, so
 * {@see checkConstraints()} states the two this table carries and nothing in the schema tool reads them —
 * `make db.diff` cannot generate them and cannot report one missing. They exist because `ActorContext`
 * makes an illegal actor unrepresentable only in PHP, while this table is also written by raw SQL (Behat
 * seeds, functional fixtures, an operator's `psql`): a `user` row with a `NULL` `actor_id` keeps its `ip` and
 * `user_agent` through both erasure passes — the actor pass selects by `actor_id`, and the resource pass
 * redacts those two columns only on `anonymous` rows. Both constraints are derived from {@see ActorType}, so
 * a new actor type changes what the method returns and `AuditLogActorCheckConstraintFunctionalTest` reds
 * until a migration re-declares the constraint to match. The actor erasure satisfies them by construction:
 * it re-mints `actor_id` to a fresh pseudonym rather than nulling it, so an erased `user` row keeps an id.
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
    public function __construct()
    {
        parent::__construct('audit_log');
    }

    /**
     * The `CHECK` constraints `audit_log` carries, keyed by constraint name: `actor_type` holds a token of
     * {@see ActorType}, and `actor_id` is present exactly when that type {@see ActorType::isIdentified()}.
     * The pairing is written as an equality of two booleans so one predicate refuses both illegal shapes —
     * an id on an actor that names nobody, and an identified actor without one.
     *
     * @return array<non-empty-string, non-empty-string>
     */
    public static function checkConstraints(): array
    {
        $unidentified = \array_filter(
            ActorType::cases(),
            static fn (ActorType $type): bool => !$type->isIdentified(),
        );

        return [
            'audit_log_actor_type_check' => \sprintf('actor_type IN (%s)', self::tokenList(ActorType::cases())),
            'audit_log_actor_id_presence_check' => \sprintf(
                '(actor_type IN (%s)) = (actor_id IS NULL)',
                self::tokenList($unidentified),
            ),
        ];
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

    /**
     * Sorted, so the order the enum happens to declare its cases in is not part of the constraint — Postgres
     * keeps the list as written, and a reordering would otherwise read as a different definition.
     *
     * @param array<ActorType> $types
     */
    private static function tokenList(array $types): string
    {
        // `IN ()` is not SQL: an enum with no unidentified case would need the predicate rewritten, not emptied.
        if ([] === $types) {
            throw new LogicException('An audit_log CHECK cannot be built over an empty set of actor types.');
        }

        $tokens = \array_map(static fn (ActorType $type): string => $type->value, $types);
        \sort($tokens);

        return \implode(', ', \array_map(
            static fn (string $token): string => "'" . \str_replace("'", "''", $token) . "'",
            $tokens,
        ));
    }
}
