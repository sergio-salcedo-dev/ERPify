<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedTableSchemaListener;

/**
 * Injects `document_series` into Doctrine's in-memory schema. The table is written and locked through plain DBAL
 * ({@see DbalDocumentVersionSlots}) and mapped by no entity: its row is the series' mutex and holds nothing a
 * transition reads, so it is not an aggregate. Runs before the foreign-key listener that points `document.series_id`
 * at it, which skips a table the schema does not hold yet.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema, priority: 10)]
final class DocumentSeriesSchemaListener extends InjectedTableSchemaListener
{
    public function __construct()
    {
        parent::__construct('document_series');
    }

    protected function define(TableEditor $table): TableEditor
    {
        return $table
            ->setColumns(
                $this->column('id', Types::GUID)->create(),
                $this->column('created_at', Types::DATETIMETZ_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
        ;
    }
}
