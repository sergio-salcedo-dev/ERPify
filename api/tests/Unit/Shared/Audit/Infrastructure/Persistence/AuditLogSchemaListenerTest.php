<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Shared\Audit\Infrastructure\Persistence\AuditLogSchemaListener;
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
 * @phpstan-import-type IndexShape from SchemaShape
 *
 * @internal
 */
#[CoversClass(AuditLogSchemaListener::class)]
#[CoversClass(InjectedTableSchemaListener::class)]
final class AuditLogSchemaListenerTest extends TestCase
{
    private const string TABLE = 'audit_log';

    #[Test]
    public function itInjectsTheAppendOnlyAuditLogTable(): void
    {
        $args = new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            Schema::editor()->create(),
        );

        (new AuditLogSchemaListener())->postGenerateSchema($args);

        $table = $args->getSchema()->getTable(self::TABLE);

        $this->assertSame($this->expectedColumns(), SchemaShape::columnsOf($table));
        $this->assertSame($this->expectedIndexes(), SchemaShape::indexesOf($table));
        $this->assertSame(['id'], SchemaShape::primaryKeyOf($table));
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $listener = new AuditLogSchemaListener();
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
            'id' => ColumnShape::of(Types::GUID),
            'level' => ColumnShape::of(Types::STRING)->length(16),
            'action' => ColumnShape::of(Types::STRING)->length(100),
            'actor_type' => ColumnShape::of(Types::STRING)->length(16),
            'actor_id' => ColumnShape::of(Types::GUID)->nullable(),
            'correlation_id' => ColumnShape::of(Types::GUID),
            'resource_type' => ColumnShape::of(Types::STRING)->nullable()->length(100),
            'resource_id' => ColumnShape::of(Types::GUID)->nullable(),
            'metadata' => ColumnShape::of(Types::JSONB),
            'ip' => ColumnShape::of(Types::STRING)->nullable()->length(45),
            'user_agent' => ColumnShape::of(Types::STRING)->nullable()->length(512),
            'actor_erased' => ColumnShape::of(Types::BOOLEAN)->defaultValue(false),
            'resource_erased' => ColumnShape::of(Types::BOOLEAN)->defaultValue(false),
            'occurred_on' => ColumnShape::of(Types::DATETIMETZ_IMMUTABLE),
            'encryption_scope_id' => ColumnShape::of(Types::STRING)->nullable()->length(160),
        ]);
    }

    /** @return array<string, IndexShape> */
    private function expectedIndexes(): array
    {
        return [
            'audit_log_actor_idx' => SchemaShape::regularIndex('actor_id', 'occurred_on'),
            'audit_log_actor_type_idx' => SchemaShape::regularIndex('actor_type', 'occurred_on'),
            'audit_log_correlation_idx' => SchemaShape::regularIndex('correlation_id'),
            'audit_log_level_idx' => SchemaShape::regularIndex('level', 'occurred_on'),
            'audit_log_resource_idx' => SchemaShape::regularIndex('resource_type', 'resource_id'),
            'audit_log_timeline_idx' => SchemaShape::regularIndex('occurred_on', 'id'),
        ];
    }
}
