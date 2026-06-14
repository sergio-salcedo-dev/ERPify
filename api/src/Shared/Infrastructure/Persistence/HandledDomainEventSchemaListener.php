<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Injects the `handled_domain_event` claim table into Doctrine's in-memory schema. The table is
 * written through plain DBAL (see {@see \Erpify\Shared\Infrastructure\Messenger\DbalDomainEventHandlerDeduplicator})
 * and has no ORM entity, so without this listener `make db.diff` would generate a DROP for it.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class HandledDomainEventSchemaListener
{
    private const string TABLE = 'handled_domain_event';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('event_id', Types::STRING, ['length' => 36]);
        $table->addColumn('handler', Types::STRING, ['length' => 190]);
        $table->addColumn('claimed_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames('event_id', 'handler')
                ->create(),
        );
    }
}
