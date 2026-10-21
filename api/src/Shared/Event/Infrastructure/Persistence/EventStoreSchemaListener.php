<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedTableSchemaListener;

/**
 * Injects the permanent `event_store` table — append-only, with a closed set of sanctioned mutations, the
 * GDPR erasure being the only one today — into Doctrine's in-memory schema. The table
 * is written through plain DBAL ({@see DbalEventStore}) and has no ORM entity, so without this
 * listener `make db.diff` would generate a DROP for it. This listener is the **source of truth** for
 * the table's shape — the baseline migration is generated from it (ADR D4).
 *
 * Doctrine's schema abstraction can only express a subset of the ADR's ideal DDL: the database-side
 * `DEFAULT now()` / `GENERATED ALWAYS` / `CHECK` clauses cannot be modelled, so `sequence` is an
 * `autoincrement` identity (still DB-assigned), and `recorded_on`/`metadata` are populated by the
 * writer ({@see DbalEventStore::append()}) rather than by a column default. The behaviour is
 * equivalent; only the literal DDL differs.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class EventStoreSchemaListener extends InjectedTableSchemaListener
{
    public function __construct()
    {
        parent::__construct('event_store');
    }

    protected function define(TableEditor $table): TableEditor
    {
        return $table
            ->setColumns(
                $this->column('sequence', Types::BIGINT)->setAutoincrement(true)->create(),
                $this->column('event_id', Types::GUID)->create(),
                $this->column('aggregate_id', Types::GUID)->create(),
                $this->column('aggregate_type', Types::STRING)->setLength(120)->create(),
                $this->column('aggregate_version', Types::INTEGER)->create(),
                $this->column('event_name', Types::STRING)->setLength(190)->create(),
                $this->column('event_version', Types::SMALLINT)->setDefaultValue(1)->create(),
                $this->column('payload', Types::JSONB)->create(),
                $this->column('metadata', Types::JSONB)->create(),
                $this->column('tenant_id', Types::GUID)->setNotNull(false)->create(),
                $this->column('occurred_on', Types::DATETIMETZ_IMMUTABLE)->create(),
                $this->column('recorded_on', Types::DATETIMETZ_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('sequence')->create(),
            )
            ->setIndexes(
                $this->index('event_store_event_id_uniq', 'event_id')
                    ->setType(IndexType::UNIQUE)
                    ->create(),
                $this->index('event_store_stream_version_uniq', 'tenant_id', 'aggregate_id', 'aggregate_version')
                    ->setType(IndexType::UNIQUE)
                    ->create(),
                $this->index('event_store_aggregate_idx', 'aggregate_type', 'aggregate_id', 'sequence')->create(),
                $this->index('event_store_name_idx', 'event_name', 'sequence')->create(),
                $this->index('event_store_recorded_idx', 'recorded_on')->create(),
            )
        ;
    }
}
