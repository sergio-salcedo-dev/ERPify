<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Backoffice\Bank\Infrastructure\Persistence\BankCountSchemaListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankCountSchemaListener::class)]
final class BankCountSchemaListenerTest extends TestCase
{
    #[Test]
    public function itInjectsTheSingletonReadModelTable(): void
    {
        $schema = new Schema();

        (new BankCountSchemaListener())->postGenerateSchema(new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            $schema,
        ));

        $this->assertTrue($schema->hasTable('bank_count'));
        $table = $schema->getTable('bank_count');
        $this->assertTrue($table->hasColumn('id'));
        $this->assertTrue($table->hasColumn('total'));
        $this->assertTrue($table->hasColumn('updated_at'));
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $schema = new Schema();
        $listener = new BankCountSchemaListener();
        $args = new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema);

        $listener->postGenerateSchema($args);
        $listener->postGenerateSchema($args);

        $this->assertTrue($schema->hasTable('bank_count'));
    }
}
