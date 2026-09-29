<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Shared\Event\Infrastructure\Persistence\EventStoreSchemaListener;
use Erpify\Tests\Support\Persistence\ColumnShape;
use Erpify\Tests\Support\Persistence\SchemaFixture;
use Erpify\Tests\Support\Persistence\SchemaShape;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The listener is the only source of the table's shape, so every load-bearing property is pinned here: a
 * transcription slip would otherwise surface as a destructive migration on the next `make db.diff`.
 *
 * @phpstan-import-type Shape from ColumnShape
 * @phpstan-import-type IndexShape from SchemaShape
 *
 * @internal
 */
#[CoversClass(EventStoreSchemaListener::class)]
final class EventStoreSchemaListenerTest extends TestCase
{
    private const string TABLE = 'event_store';

    #[Test]
    public function itInjectsTheAppendOnlyEventStoreTable(): void
    {
        $table = $this->dispatch(Schema::editor()->create())->getTable(self::TABLE);

        $this->assertSame($this->expectedColumns(), SchemaShape::columnsOf($table));
        $this->assertSame($this->expectedIndexes(), SchemaShape::indexesOf($table));
        $this->assertSame(['sequence'], SchemaShape::primaryKeyOf($table));
    }

    #[Test]
    public function itInjectsTheSameTableIntoASchemaCarryingADefaultNamespace(): void
    {
        $schema = $this->dispatch(Schema::editor()->setDefaultNamespace('public')->create());

        $this->assertTrue($schema->hasTable('public.' . self::TABLE));
        $table = $schema->getTable(self::TABLE);
        $this->assertSame($this->expectedColumns(), SchemaShape::columnsOf($table));
        $this->assertSame($this->expectedIndexes(), SchemaShape::indexesOf($table));
        $this->assertSame(['sequence'], SchemaShape::primaryKeyOf($table));
    }

    #[Test]
    public function itPreservesEveryOtherTableAndSequenceOfTheSchema(): void
    {
        $schema = $this->dispatch(
            Schema::editor()
                ->addTable(SchemaFixture::tableWithPrimaryKey('bank', 'id'))
                ->addTable(
                    SchemaFixture::tableWithPrimaryKey('bank_account', 'id', 'bank_id')
                        ->edit()
                        ->addIndex(SchemaFixture::index('bank_account_bank_idx', 'bank_id'))
                        ->addForeignKeyConstraint(SchemaFixture::foreignKey('bank_account_bank_fk', 'bank_id', 'bank'))
                        ->create(),
                )
                ->addSequence(SchemaFixture::sequence('invoice_number_seq', 10))
                ->create(),
        );

        $this->assertTrue($schema->hasTable(self::TABLE));
        $this->assertTrue($schema->hasTable('bank'));
        $bankAccount = $schema->getTable('bank_account');
        $this->assertSame(
            ['bank_account_bank_idx' => SchemaShape::regularIndex('bank_id')],
            SchemaShape::indexesOf($bankAccount),
        );
        $this->assertSame(['id'], SchemaShape::primaryKeyOf($bankAccount));
        $foreignKey = $bankAccount->getForeignKey('bank_account_bank_fk');
        $this->assertSame(['bank_id'], SchemaShape::names($foreignKey->getReferencingColumnNames()));
        $this->assertSame('bank', $foreignKey->getReferencedTableName()->toString());
        $this->assertTrue($schema->hasSequence('invoice_number_seq'));
        $this->assertSame(10, $schema->getSequence('invoice_number_seq')->getAllocationSize());
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $listener = new EventStoreSchemaListener();
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
            'sequence' => ColumnShape::of(Types::BIGINT)->autoincrement(),
            'event_id' => ColumnShape::of(Types::GUID),
            'aggregate_id' => ColumnShape::of(Types::GUID),
            'aggregate_type' => ColumnShape::of(Types::STRING)->length(120),
            'aggregate_version' => ColumnShape::of(Types::INTEGER),
            'event_name' => ColumnShape::of(Types::STRING)->length(190),
            'event_version' => ColumnShape::of(Types::SMALLINT)->defaultValue(1),
            'payload' => ColumnShape::of(Types::JSONB),
            'metadata' => ColumnShape::of(Types::JSONB),
            'tenant_id' => ColumnShape::of(Types::GUID)->nullable(),
            'occurred_on' => ColumnShape::of(Types::DATETIMETZ_IMMUTABLE),
            'recorded_on' => ColumnShape::of(Types::DATETIMETZ_IMMUTABLE),
        ]);
    }

    /** @return array<string, IndexShape> */
    private function expectedIndexes(): array
    {
        return [
            'event_store_aggregate_idx' => SchemaShape::regularIndex('aggregate_type', 'aggregate_id', 'sequence'),
            'event_store_event_id_uniq' => SchemaShape::uniqueIndex('event_id'),
            'event_store_name_idx' => SchemaShape::regularIndex('event_name', 'sequence'),
            'event_store_recorded_idx' => SchemaShape::regularIndex('recorded_on'),
            'event_store_stream_version_uniq' => SchemaShape::uniqueIndex(
                'tenant_id',
                'aggregate_id',
                'aggregate_version',
            ),
        ];
    }

    private function dispatch(Schema $schema): Schema
    {
        $args = new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema);

        (new EventStoreSchemaListener())->postGenerateSchema($args);

        return $args->getSchema();
    }
}
