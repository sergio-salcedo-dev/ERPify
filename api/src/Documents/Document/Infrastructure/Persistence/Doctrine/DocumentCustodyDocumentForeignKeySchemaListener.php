<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedForeignKeySchemaListener;

/**
 * Re-injects the physical `document_custody.document_id` → `document.id` foreign key into Doctrine's in-memory
 * schema. The custody row references its document by a plain column, so the ORM derives no constraint from the
 * mapping and the schema diff would never propose one.
 *
 * The key is kept because both tables belong to this module, and it has NO cascade: a document is never
 * physically deleted (erasure is a state), so `ON DELETE CASCADE` would only ever fire as a mistake, and the
 * custody rows must outlive anything that tried. `DocumentSchemaFunctionalTest` asserts the absence of a cascade.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class DocumentCustodyDocumentForeignKeySchemaListener extends InjectedForeignKeySchemaListener
{
    public function __construct()
    {
        parent::__construct(
            table: 'document_custody',
            column: 'document_id',
            referencedTable: 'document',
            foreignKey: 'fk_document_custody_document',
        );
    }
}
