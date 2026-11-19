<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedForeignKeySchemaListener;

/**
 * Re-injects `document.series_id` → `document_series.id`: every series a document names has the row its takes lock, so
 * the lock can never find nothing to hold. No cascade — neither row is ever deleted. An `UPDATE` of a document never
 * touches the column (`updatable: false`), so the scan and erasure statements take no lock on the series row through
 * this key; only an insert does, and the only insert into an existing series already holds that row.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class DocumentSeriesForeignKeySchemaListener extends InjectedForeignKeySchemaListener
{
    public function __construct()
    {
        parent::__construct(
            table: 'document',
            column: 'series_id',
            referencedTable: 'document_series',
            foreignKey: 'fk_document_series',
        );
    }
}
