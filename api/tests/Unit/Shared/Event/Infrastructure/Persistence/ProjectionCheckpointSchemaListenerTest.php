<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Shared\Event\Infrastructure\Persistence\ProjectionCheckpointSchemaListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProjectionCheckpointSchemaListener::class)]
final class ProjectionCheckpointSchemaListenerTest extends TestCase
{
    #[Test]
    public function itInjectsTheCheckpointTable(): void
    {
        $schema = new Schema();

        (new ProjectionCheckpointSchemaListener())->postGenerateSchema(new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            $schema,
        ));

        $this->assertTrue($schema->hasTable('projection_checkpoint'));
        $table = $schema->getTable('projection_checkpoint');
        $this->assertTrue($table->hasColumn('name'));
        $this->assertTrue($table->hasColumn('last_sequence'));
        $this->assertTrue($table->hasColumn('updated_at'));
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $schema = new Schema();
        $listener = new ProjectionCheckpointSchemaListener();
        $args = new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema);

        $listener->postGenerateSchema($args);
        $listener->postGenerateSchema($args);

        $this->assertTrue($schema->hasTable('projection_checkpoint'));
    }
}
