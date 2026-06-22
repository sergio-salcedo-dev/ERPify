<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\BankAccountForeignKeySchemaListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountForeignKeySchemaListener::class)]
final class BankAccountForeignKeySchemaListenerTest extends TestCase
{
    private const string FOREIGN_KEY = 'FK_53A23E0A11C8FB41';

    #[Test]
    public function itReinjectsTheBankAccountToBankForeignKey(): void
    {
        $schema = $this->schemaWithBothTables();

        $this->listener()->postGenerateSchema($this->args($schema));

        $this->assertTrue($schema->getTable('bank_account')->hasForeignKey(self::FOREIGN_KEY));
    }

    #[Test]
    public function itIsIdempotentWhenTheForeignKeyAlreadyExists(): void
    {
        $schema = $this->schemaWithBothTables();
        $listener = $this->listener();
        $args = $this->args($schema);

        $listener->postGenerateSchema($args);
        $listener->postGenerateSchema($args);

        $foreignKeys = $schema->getTable('bank_account')->getForeignKeys();
        $this->assertCount(1, $foreignKeys);
    }

    #[Test]
    public function itDoesNothingWhenTheReferencedTableIsAbsent(): void
    {
        $schema = new Schema();
        $bankAccount = $schema->createTable('bank_account');
        $bankAccount->addColumn('bank_id', Types::GUID);

        $this->listener()->postGenerateSchema($this->args($schema));

        $this->assertFalse($schema->getTable('bank_account')->hasForeignKey(self::FOREIGN_KEY));
    }

    private function schemaWithBothTables(): Schema
    {
        $schema = new Schema();

        $bank = $schema->createTable('bank');
        $bank->addColumn('id', Types::GUID);

        $bankAccount = $schema->createTable('bank_account');
        $bankAccount->addColumn('bank_id', Types::GUID);

        return $schema;
    }

    private function listener(): BankAccountForeignKeySchemaListener
    {
        return new BankAccountForeignKeySchemaListener();
    }

    private function args(Schema $schema): GenerateSchemaEventArgs
    {
        return new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema);
    }
}
