<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedTableSchemaListener;

/**
 * Injects the singleton `bank_count` read-model table into Doctrine's in-memory schema. Written
 * through plain DBAL ({@see \Erpify\Backoffice\Bank\Infrastructure\Projection\DbalBankCountReadModel}),
 * no ORM entity. The singleton `CHECK (id = 1)` of the ADR is not modelled (Doctrine's schema
 * abstraction cannot express it); the invariant is enforced by the read model always upserting `id = 1`.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class BankCountSchemaListener extends InjectedTableSchemaListener
{
    public function __construct()
    {
        parent::__construct('bank_count');
    }

    protected function define(TableEditor $table): TableEditor
    {
        return $table
            ->setColumns(
                $this->column('id', Types::SMALLINT)->setDefaultValue(1)->create(),
                $this->column('total', Types::INTEGER)->setDefaultValue(0)->create(),
                $this->column('updated_at', Types::DATETIMETZ_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
        ;
    }
}
