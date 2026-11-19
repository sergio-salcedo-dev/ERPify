<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedForeignKeySchemaListener;

/**
 * Re-injects `document.predecessor_id` → `document.id`: a version names a predecessor that exists. Its backing index is
 * also what the slot's recursive read walks down. No cascade, since a document is never deleted, and the insert's key
 * lock on the predecessor is one the new-version commit already holds as `FOR UPDATE`.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class DocumentPredecessorForeignKeySchemaListener extends InjectedForeignKeySchemaListener
{
    public function __construct()
    {
        parent::__construct(
            table: 'document',
            column: 'predecessor_id',
            referencedTable: 'document',
            foreignKey: 'fk_document_predecessor',
        );
    }
}
