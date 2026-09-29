<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Shared\Event\Infrastructure\Persistence\HandledDomainEventSchemaListener;
use Erpify\Tests\Support\Persistence\ColumnShape;
use Erpify\Tests\Support\Persistence\SchemaShape;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The listener is the only source of the table's shape, so every load-bearing property is pinned here: a
 * transcription slip would otherwise surface as a destructive migration on the next `make db.diff`.
 *
 * @phpstan-import-type Shape from ColumnShape
 *
 * @internal
 */
#[CoversClass(HandledDomainEventSchemaListener::class)]
final class HandledDomainEventSchemaListenerTest extends TestCase
{
    private const string TABLE = 'handled_domain_event';

    #[Test]
    public function itInjectsTheClaimTable(): void
    {
        $args = new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            Schema::editor()->create(),
        );

        (new HandledDomainEventSchemaListener())->postGenerateSchema($args);

        $table = $args->getSchema()->getTable(self::TABLE);

        $this->assertSame($this->expectedColumns(), SchemaShape::columnsOf($table));
        $this->assertSame([], SchemaShape::indexesOf($table));
        $this->assertSame(['event_id', 'handler'], SchemaShape::primaryKeyOf($table));
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $listener = new HandledDomainEventSchemaListener();
        $args = new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            Schema::editor()->create(),
        );

        $listener->postGenerateSchema($args);
        $injected = $args->getSchema();
        $listener->postGenerateSchema($args);

        $this->assertTrue($injected->hasTable(self::TABLE));
        $this->assertSame($injected, $args->getSchema());
    }

    /** @return array<string, Shape> */
    private function expectedColumns(): array
    {
        return ColumnShape::toArrays([
            'event_id' => ColumnShape::of(Types::STRING)->length(36),
            'handler' => ColumnShape::of(Types::STRING)->length(190),
            'claimed_at' => ColumnShape::of(Types::DATETIME_IMMUTABLE),
        ]);
    }
}
