<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Backoffice\Bank\Infrastructure\Persistence\BankCountSchemaListener;
use Erpify\Shared\Persistence\Infrastructure\InjectedTableSchemaListener;
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
#[CoversClass(BankCountSchemaListener::class)]
#[CoversClass(InjectedTableSchemaListener::class)]
final class BankCountSchemaListenerTest extends TestCase
{
    private const string TABLE = 'bank_count';

    #[Test]
    public function itInjectsTheSingletonReadModelTable(): void
    {
        $args = new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            Schema::editor()->create(),
        );

        (new BankCountSchemaListener())->postGenerateSchema($args);

        $table = $args->getSchema()->getTable(self::TABLE);

        $this->assertSame($this->expectedColumns(), SchemaShape::columnsOf($table));
        $this->assertSame([], SchemaShape::indexesOf($table));
        $this->assertSame(['id'], SchemaShape::primaryKeyOf($table));
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $listener = new BankCountSchemaListener();
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
            'id' => ColumnShape::of(Types::SMALLINT)->defaultValue(1),
            'total' => ColumnShape::of(Types::INTEGER)->defaultValue(0),
            'updated_at' => ColumnShape::of(Types::DATETIMETZ_IMMUTABLE),
        ]);
    }
}
