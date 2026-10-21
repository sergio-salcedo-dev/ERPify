<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedTableSchemaListener;

/**
 * Injects the `projection_checkpoint` table (last applied `sequence` per projection) into Doctrine's
 * in-memory schema. Written through plain DBAL
 * ({@see \Erpify\Shared\Event\Infrastructure\Projection\DbalProjectionCheckpointStore}), no ORM entity
 * — without this listener the generated baseline would omit it and `make db.diff` would never settle.
 * `updated_at` is set by the writer (no DB-side `DEFAULT now()`).
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class ProjectionCheckpointSchemaListener extends InjectedTableSchemaListener
{
    public function __construct()
    {
        parent::__construct('projection_checkpoint');
    }

    protected function define(TableEditor $table): TableEditor
    {
        return $table
            ->setColumns(
                $this->column('name', Types::STRING)->setLength(120)->create(),
                $this->column('last_sequence', Types::BIGINT)->setDefaultValue(0)->create(),
                $this->column('updated_at', Types::DATETIMETZ_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('name')->create(),
            )
        ;
    }
}
