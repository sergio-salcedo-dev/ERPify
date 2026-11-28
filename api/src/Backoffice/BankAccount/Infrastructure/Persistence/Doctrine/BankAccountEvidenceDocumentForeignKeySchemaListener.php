<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedForeignKeySchemaListener;

/**
 * Re-injects the physical `bank_account_evidence.document_id` → `document.id` foreign key, without cascade and not
 * deferrable. It crosses a bounded context — the Level 2 coupling `docs/rules/database.md` discourages — and it is
 * kept by decision rather than by default, because what it guards is irreversible: a document is never deleted (an
 * erasure changes its state and keeps the tombstone), so the key costs Documents no write it would otherwise make,
 * while without it a row removed outside every path either context owns would leave the account relying on evidence
 * that no longer exists, with nothing to say so. No ORM association backs it and no object graph crosses: the link
 * holds the id, and everything it learns about the document comes through the read seam Documents publishes.
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
            foreignKey: 'fk_bank_account_evidence_document',
        );
    }
}
