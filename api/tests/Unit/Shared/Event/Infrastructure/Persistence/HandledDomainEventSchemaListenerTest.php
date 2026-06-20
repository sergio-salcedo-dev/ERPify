<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Shared\Event\Infrastructure\Persistence\HandledDomainEventSchemaListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(HandledDomainEventSchemaListener::class)]
final class HandledDomainEventSchemaListenerTest extends TestCase
{
    #[Test]
    public function itInjectsTheClaimTable(): void
    {
        $schema = new Schema();

        (new HandledDomainEventSchemaListener())->postGenerateSchema(new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            $schema,
        ));

        $this->assertTrue($schema->hasTable('handled_domain_event'));
        $table = $schema->getTable('handled_domain_event');
        $this->assertTrue($table->hasColumn('event_id'));
        $this->assertTrue($table->hasColumn('handler'));
        $this->assertTrue($table->hasColumn('claimed_at'));
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $schema = new Schema();
        $listener = new HandledDomainEventSchemaListener();
        $args = new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema);

        $listener->postGenerateSchema($args);
        $listener->postGenerateSchema($args);

        $this->assertTrue($schema->hasTable('handled_domain_event'));
    }
}
