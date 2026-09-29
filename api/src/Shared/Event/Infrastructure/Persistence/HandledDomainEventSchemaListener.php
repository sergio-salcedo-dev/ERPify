<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Injects the `handled_domain_event` claim table into Doctrine's in-memory schema. The table is
 * written through plain DBAL
 * ({@see \Erpify\Shared\Event\Infrastructure\Messenger\DbalDomainEventHandlerDeduplicator}) and has no
 * ORM entity, so without this listener `make db.diff` would generate a DROP for it.
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

        $table = Table::editor()
            ->setUnquotedName(self::TABLE)
            ->setColumns(
                $this->column('event_id', Types::STRING)->setLength(36)->create(),
                $this->column('handler', Types::STRING)->setLength(190)->create(),
                $this->column('claimed_at', Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('event_id', 'handler')->create(),
            )
            ->create()
        ;

        $args->setSchema($schema->edit()->addTable($table)->create());
    }

    /** @param non-empty-string $name */
    private function column(string $name, string $type): ColumnEditor
    {
        return Column::editor()->setUnquotedName($name)->setTypeName($type);
    }
}
