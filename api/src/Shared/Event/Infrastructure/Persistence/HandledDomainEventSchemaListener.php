<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedTableSchemaListener;

/**
 * Injects the `handled_domain_event` claim table into Doctrine's in-memory schema. The table is
 * written through plain DBAL
 * ({@see \Erpify\Shared\Event\Infrastructure\Messenger\DbalDomainEventHandlerDeduplicator}) and has no
 * ORM entity, so without this listener `make db.diff` would generate a DROP for it.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class HandledDomainEventSchemaListener extends InjectedTableSchemaListener
{
    public function __construct()
    {
        parent::__construct('handled_domain_event');
    }

    protected function define(TableEditor $table): TableEditor
    {
        return $table
            ->setColumns(
                $this->column('event_id', Types::STRING)->setLength(36)->create(),
                $this->column('handler', Types::STRING)->setLength(190)->create(),
                $this->column('claimed_at', Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('event_id', 'handler')->create(),
            )
        ;
    }
}
