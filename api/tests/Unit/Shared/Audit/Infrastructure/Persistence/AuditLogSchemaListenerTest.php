<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Shared\Audit\Infrastructure\Persistence\AuditLogSchemaListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditLogSchemaListener::class)]
final class AuditLogSchemaListenerTest extends TestCase
{
    #[Test]
    public function itInjectsTheAppendOnlyAuditLogTable(): void
    {
        $schema = new Schema();

        (new AuditLogSchemaListener())->postGenerateSchema(new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            $schema,
        ));

        $this->assertTrue($schema->hasTable('audit_log'));
        $table = $schema->getTable('audit_log');

        foreach (
            ['id', 'level', 'action', 'actor_type', 'actor_id', 'correlation_id', 'resource_type',
                'resource_id', 'metadata', 'ip', 'user_agent', 'actor_erased', 'occurred_on'] as $column
        ) {
            $this->assertTrue($table->hasColumn($column), \sprintf('expected column "%s"', $column));
        }

        foreach (
            ['audit_log_actor_idx', 'audit_log_correlation_idx', 'audit_log_level_idx',
                'audit_log_resource_idx'] as $index
        ) {
            $this->assertTrue($table->hasIndex($index), \sprintf('expected index "%s"', $index));
        }

        $this->assertFalse($table->getColumn('actor_id')->getNotnull(), 'actor_id is nullable');
        $this->assertTrue($table->getColumn('correlation_id')->getNotnull(), 'correlation_id is not-null');
        $this->assertTrue($table->getColumn('metadata')->getNotnull(), 'metadata is not-null');
        $this->assertTrue($table->getColumn('actor_erased')->getNotnull(), 'actor_erased is not-null');

        $this->assertSame(16, $table->getColumn('level')->getLength());
        $this->assertSame(16, $table->getColumn('actor_type')->getLength());
        $this->assertSame(100, $table->getColumn('action')->getLength());
        $this->assertSame(100, $table->getColumn('resource_type')->getLength());
        $this->assertSame(45, $table->getColumn('ip')->getLength());
        $this->assertSame(512, $table->getColumn('user_agent')->getLength());
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $schema = new Schema();
        $listener = new AuditLogSchemaListener();
        $args = new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema);

        $listener->postGenerateSchema($args);
        $listener->postGenerateSchema($args);

        $this->assertTrue($schema->hasTable('audit_log'));
    }
}
