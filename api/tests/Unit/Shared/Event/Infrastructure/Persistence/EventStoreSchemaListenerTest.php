<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Shared\Event\Infrastructure\Persistence\EventStoreSchemaListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EventStoreSchemaListener::class)]
final class EventStoreSchemaListenerTest extends TestCase
{
    #[Test]
    public function itInjectsTheAppendOnlyEventStoreTable(): void
    {
        $schema = new Schema();

        (new EventStoreSchemaListener())->postGenerateSchema(new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            $schema,
        ));

        $this->assertTrue($schema->hasTable('event_store'));
        $table = $schema->getTable('event_store');

        foreach (
            ['sequence', 'event_id', 'aggregate_id', 'aggregate_version', 'event_name', 'payload',
                'metadata', 'tenant_id', 'occurred_on', 'recorded_on'] as $column
        ) {
            $this->assertTrue($table->hasColumn($column), \sprintf('expected column "%s"', $column));
        }

        $this->assertTrue($table->hasIndex('event_store_event_id_uniq'));
        $this->assertTrue($table->hasIndex('event_store_stream_version_uniq'));
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $schema = new Schema();
        $listener = new EventStoreSchemaListener();
        $args = new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema);

        $listener->postGenerateSchema($args);
        $listener->postGenerateSchema($args);

        $this->assertTrue($schema->hasTable('event_store'));
    }
}
