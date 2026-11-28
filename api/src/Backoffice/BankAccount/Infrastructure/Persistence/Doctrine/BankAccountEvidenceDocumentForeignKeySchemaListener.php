<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedForeignKeySchemaListener;

/**
 * Re-injects the physical `bank_account_evidence.document_id` → `document.id` foreign key into Doctrine's in-memory
 * schema: the link references its document by id through a plain column, so the ORM derives no foreign key from
 * metadata and the schema diff would otherwise drop it. No cascade and not deferrable, so the key refuses removing the
 * referenced row while a link names it, at the statement that tries.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class BankAccountEvidenceDocumentForeignKeySchemaListener extends InjectedForeignKeySchemaListener
{
    public function __construct()
    {
        parent::__construct(
            table: 'bank_account_evidence',
            column: 'document_id',
            referencedTable: 'document',
            foreignKey: 'FK_BANK_ACCOUNT_EVIDENCE_DOCUMENT',
        );
    }
}
