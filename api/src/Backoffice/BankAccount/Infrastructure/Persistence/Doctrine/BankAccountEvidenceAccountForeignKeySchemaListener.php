<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedForeignKeySchemaListener;

/**
 * Re-injects the physical `bank_account_evidence.bank_account_id` → `bank_account.id` foreign key, without cascade
 * and not deferrable. The link references its account by id through a plain column, so the ORM derives no key from
 * metadata; the key is what makes an attach and the account's removal meet as a lock wait rather than as an orphan,
 * and a removal of the account that skipped its links fail rather than leave them behind.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class BankAccountEvidenceAccountForeignKeySchemaListener extends InjectedForeignKeySchemaListener
{
    public function __construct()
    {
        parent::__construct(
            table: 'bank_account_evidence',
            column: 'bank_account_id',
            referencedTable: 'bank_account',
            foreignKey: 'fk_bank_account_evidence_bank_account',
        );
    }
}
