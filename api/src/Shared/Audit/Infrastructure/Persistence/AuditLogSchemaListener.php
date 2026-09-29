<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\IndexEditor;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Injects the permanent, append-only `audit_log` table into Doctrine's in-memory schema. The table is
 * written through plain DBAL ({@see DbalAuditLogWriter}) and has no ORM entity, so without this listener
 * `make db.diff` would generate a DROP for it. This listener is the **source of truth** for the table's
 * shape — the migration is generated from it.
 *
 * Doctrine's schema abstraction expresses only a subset of the ideal DDL: there is no `CHECK` and no native
 * Postgres `ENUM`, so `level`/`actor_type` are plain `VARCHAR` holding the backing value of their PHP enum
 * (the enum, not the database, is the closed set). `ip` is `VARCHAR(45)` because DBAL models no `inet` type
 * and no query needs subnet operators.
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
final class AuditLogSchemaListener
{
    private const string TABLE = 'audit_log';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $table = Table::editor()
            ->setUnquotedName(self::TABLE)
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
            ->create()
        ;

        $args->setSchema($schema->edit()->addTable($table)->create());
    }

    /** @param non-empty-string $name */
    private function column(string $name, string $type): ColumnEditor
    {
        return Column::editor()->setUnquotedName($name)->setTypeName($type);
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $firstColumn
     * @param non-empty-string ...$otherColumns
     */
    private function index(string $name, string $firstColumn, string ...$otherColumns): IndexEditor
    {
        return Index::editor()->setUnquotedName($name)->setUnquotedColumnNames($firstColumn, ...$otherColumns);
    }
}
