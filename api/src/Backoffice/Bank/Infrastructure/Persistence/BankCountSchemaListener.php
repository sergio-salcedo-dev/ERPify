<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Injects the singleton `bank_count` read-model table into Doctrine's in-memory schema. Written
 * through plain DBAL ({@see \Erpify\Backoffice\Bank\Infrastructure\Projection\DbalBankCountReadModel}),
 * no ORM entity. The singleton `CHECK (id = 1)` of the ADR is not modelled (Doctrine's schema
 * abstraction cannot express it); the invariant is enforced by the read model always upserting `id = 1`.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class BankCountSchemaListener
{
    private const string TABLE = 'bank_count';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('id', Types::SMALLINT, ['default' => 1]);
        $table->addColumn('total', Types::INTEGER, ['default' => 0]);
        $table->addColumn('updated_at', Types::DATETIMETZ_IMMUTABLE);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
    }
}
