<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Injects the `projection_checkpoint` table (last applied `sequence` per projection) into Doctrine's
 * in-memory schema. Written through plain DBAL
 * ({@see \Erpify\Shared\Event\Infrastructure\Projection\DbalProjectionCheckpointStore}), no ORM entity
 * — without this listener the generated baseline would omit it and `make db.diff` would never settle.
 * `updated_at` is set by the writer (no DB-side `DEFAULT now()`).
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class ProjectionCheckpointSchemaListener
{
    private const string TABLE = 'projection_checkpoint';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('name', Types::STRING, ['length' => 120]);
        $table->addColumn('last_sequence', Types::BIGINT, ['default' => 0]);
        $table->addColumn('updated_at', Types::DATETIMETZ_IMMUTABLE);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('name')->create(),
        );
    }
}
