<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\BankAccountForeignKeySchemaListener;
use Erpify\Tests\Support\Persistence\SchemaFixture;
use Erpify\Tests\Support\Persistence\SchemaShape;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type IndexShape from SchemaShape
 *
 * @internal
 */
#[CoversClass(BankAccountForeignKeySchemaListener::class)]
final class BankAccountForeignKeySchemaListenerTest extends TestCase
{
    private const string FOREIGN_KEY = 'FK_53A23E0A11C8FB41';

    /**
     * The backing index DBAL derives from the table+column hash; the committed migration creates it under
     * this name, so a different one would surface as a DROP/CREATE pair on the next `make db.diff`.
     */
    private const string BACKING_INDEX = 'IDX_53A23E0A11C8FB41';

    #[Test]
    public function itReinjectsTheBankAccountToBankForeignKey(): void
    {
        $args = $this->args($this->schemaWithBothTables(Schema::editor()));

        $this->listener()->postGenerateSchema($args);

        $this->assertForeignKeyAndBackingIndex($args->getSchema()->getTable('bank_account'));
    }

    #[Test]
    public function itReinjectsTheForeignKeyIntoASchemaCarryingADefaultNamespace(): void
    {
        $args = $this->args($this->schemaWithBothTables(Schema::editor()->setDefaultNamespace('public')));

        $this->listener()->postGenerateSchema($args);

        $schema = $args->getSchema();
        $this->assertTrue($schema->hasTable('public.bank_account'));
        $this->assertForeignKeyAndBackingIndex($schema->getTable('bank_account'));
    }

    #[Test]
    public function itPreservesEverythingElseInTheSchema(): void
    {
        $bankAccount = SchemaFixture::table('bank_account', 'bank_id', 'iban')
            ->edit()
            ->addIndex(SchemaFixture::index('bank_account_iban_idx', 'iban'))
            ->create()
        ;
        $invoice = SchemaFixture::table('invoice', 'id', 'bank_id')
            ->edit()
            ->addIndex(SchemaFixture::index('invoice_bank_idx', 'bank_id'))
            ->addForeignKeyConstraint(SchemaFixture::foreignKey('invoice_bank_fk', 'bank_id', 'bank'))
            ->create()
        ;
        $args = $this->args(
            Schema::editor()
                ->addTable(SchemaFixture::table('bank', 'id'))
                ->addTable($bankAccount)
                ->addTable($invoice)
                ->addSequence(SchemaFixture::sequence('invoice_number_seq', 10))
                ->create(),
        );

        $this->listener()->postGenerateSchema($args);

        $schema = $args->getSchema();
        $table = $schema->getTable('bank_account');
        $this->assertForeignKeyAndBackingIndex($table, ['bank_account_iban_idx' => SchemaShape::regularIndex('iban')]);
        $this->assertTrue($table->hasColumn('iban'));
        $this->assertTrue($schema->hasTable('bank'));
        $invoice = $schema->getTable('invoice');
        $this->assertSame(
            ['invoice_bank_idx' => SchemaShape::regularIndex('bank_id')],
            SchemaShape::indexesOf($invoice),
        );
        $this->assertSame(['invoice_bank_fk'], \array_keys($invoice->getForeignKeys()));
        $foreignKey = $invoice->getForeignKey('invoice_bank_fk');
        $this->assertSame(['bank_id'], SchemaShape::names($foreignKey->getReferencingColumnNames()));
        $this->assertTrue($schema->hasSequence('invoice_number_seq'));
        $this->assertSame(10, $schema->getSequence('invoice_number_seq')->getAllocationSize());
    }

    #[Test]
    public function itIsIdempotentWhenTheForeignKeyAlreadyExists(): void
    {
        $listener = $this->listener();
        $args = $this->args($this->schemaWithBothTables(Schema::editor()));

        $listener->postGenerateSchema($args);
        $injected = $args->getSchema();
        $listener->postGenerateSchema($args);

        $this->assertSame($injected, $args->getSchema());
        $this->assertCount(1, $injected->getTable('bank_account')->getForeignKeys());
    }

    #[Test]
    public function itDoesNothingWhenTheReferencedTableIsAbsent(): void
    {
        $schema = Schema::editor()->addTable(SchemaFixture::table('bank_account', 'bank_id'))->create();
        $args = $this->args($schema);

        $this->listener()->postGenerateSchema($args);

        $this->assertSame($schema, $args->getSchema());
        $this->assertFalse($schema->getTable('bank_account')->hasForeignKey(self::FOREIGN_KEY));
    }

    /** @param array<string, IndexShape> $otherIndexes */
    private function assertForeignKeyAndBackingIndex(Table $table, array $otherIndexes = []): void
    {
        $this->assertSame([\strtolower(self::FOREIGN_KEY)], \array_keys($table->getForeignKeys()));
        $foreignKey = $table->getForeignKey(self::FOREIGN_KEY);
        $this->assertSame(['bank_id'], SchemaShape::names($foreignKey->getReferencingColumnNames()));
        $this->assertSame('bank', $foreignKey->getReferencedTableName()->toString());
        $this->assertSame(['id'], SchemaShape::names($foreignKey->getReferencedColumnNames()));

        $expectedIndexes = [self::BACKING_INDEX => SchemaShape::regularIndex('bank_id'), ...$otherIndexes];
        \ksort($expectedIndexes);
        $this->assertSame($expectedIndexes, SchemaShape::indexesOf($table));
    }

    private function schemaWithBothTables(SchemaEditor $editor): Schema
    {
        return $editor
            ->addTable(SchemaFixture::table('bank', 'id'))
            ->addTable(SchemaFixture::table('bank_account', 'bank_id'))
            ->create()
        ;
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
