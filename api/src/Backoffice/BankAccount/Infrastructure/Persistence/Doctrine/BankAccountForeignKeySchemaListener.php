<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedForeignKeySchemaListener;

/**
 * Re-injects the physical `bank_account.bank_id` → `bank.id` foreign key into Doctrine's in-memory
 * schema. BankAccount references Bank by id through a plain column rather than a mapped association, so
 * the ORM does not derive this FK from metadata — without this listener the schema diff would drop it.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class BankAccountForeignKeySchemaListener extends InjectedForeignKeySchemaListener
{
    public function __construct()
    {
        parent::__construct(
            table: 'bank_account',
            column: 'bank_id',
            referencedTable: 'bank',
            foreignKey: 'FK_53A23E0A11C8FB41',
        );
    }
}
