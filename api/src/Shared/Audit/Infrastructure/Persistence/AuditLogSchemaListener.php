<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Injects the permanent, append-only `audit_log` table into Doctrine's in-memory schema. The table is
 * written through plain DBAL ({@see DbalAuditLogWriter}) and has no ORM entity, so without this listener
 * `make db.diff` would generate a DROP for it. This listener is the **source of truth** for the table's
 * shape — the migration is generated from it.
 *
 * Doctrine's schema abstraction expresses only a subset of the ideal DDL: there is no `CHECK`, no native
 * Postgres `ENUM` and no column `DEFAULT`, so `level`/`actor_type` are plain `VARCHAR` holding the backing
 * value of their PHP enum (the enum, not the database, is the closed set), and every value is supplied by
 * the writer rather than by a column default. `ip` is `VARCHAR(45)` because DBAL models no `inet` type and
 * no query needs subnet operators.
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

        $table = $schema->createTable(self::TABLE);

        $table->addColumn('id', Types::GUID);
        $table->addColumn('level', Types::STRING, ['length' => 16]);
        $table->addColumn('action', Types::STRING, ['length' => 100]);
        $table->addColumn('actor_type', Types::STRING, ['length' => 16]);
        $table->addColumn('actor_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('correlation_id', Types::GUID);
        $table->addColumn('resource_type', Types::STRING, ['length' => 100, 'notnull' => false]);
        $table->addColumn('resource_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('metadata', Types::JSONB);
        $table->addColumn('ip', Types::STRING, ['length' => 45, 'notnull' => false]);
        $table->addColumn('user_agent', Types::STRING, ['length' => 512, 'notnull' => false]);
        $table->addColumn('occurred_on', Types::DATETIMETZ_IMMUTABLE);

        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );

        $table->addIndex(['actor_id', 'occurred_on'], 'audit_log_actor_idx');
        $table->addIndex(['correlation_id'], 'audit_log_correlation_idx');
        $table->addIndex(['level', 'occurred_on'], 'audit_log_level_idx');
        $table->addIndex(['resource_type', 'resource_id'], 'audit_log_resource_idx');
    }
}
