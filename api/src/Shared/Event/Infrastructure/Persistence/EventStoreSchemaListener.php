<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Injects the permanent, append-only `event_store` table into Doctrine's in-memory schema. The table
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
final class EventStoreSchemaListener
{
    private const string TABLE = 'event_store';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->createTable(self::TABLE);

        $table->addColumn('sequence', Types::BIGINT, ['autoincrement' => true]);
        $table->addColumn('event_id', Types::GUID);
        $table->addColumn('aggregate_id', Types::GUID);
        $table->addColumn('aggregate_type', Types::STRING, ['length' => 120]);
        $table->addColumn('aggregate_version', Types::INTEGER);
        $table->addColumn('event_name', Types::STRING, ['length' => 190]);
        $table->addColumn('event_version', Types::SMALLINT, ['default' => 1]);
        $table->addColumn('payload', Types::JSONB);
        $table->addColumn('metadata', Types::JSONB);
        $table->addColumn('tenant_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('occurred_on', Types::DATETIMETZ_IMMUTABLE);
        $table->addColumn('recorded_on', Types::DATETIMETZ_IMMUTABLE);

        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('sequence')->create(),
        );

        $table->addUniqueIndex(['event_id'], 'event_store_event_id_uniq');
        $table->addUniqueIndex(['tenant_id', 'aggregate_id', 'aggregate_version'], 'event_store_stream_version_uniq');
        $table->addIndex(['aggregate_type', 'aggregate_id', 'sequence'], 'event_store_aggregate_idx');
        $table->addIndex(['event_name', 'sequence'], 'event_store_name_idx');
        $table->addIndex(['recorded_on'], 'event_store_recorded_idx');
    }
}
