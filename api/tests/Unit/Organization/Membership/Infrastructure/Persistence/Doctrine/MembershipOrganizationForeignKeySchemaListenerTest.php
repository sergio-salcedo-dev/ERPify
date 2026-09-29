<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine\MembershipOrganizationForeignKeySchemaListener;
use Erpify\Tests\Support\Persistence\SchemaFixture;
use Erpify\Tests\Support\Persistence\SchemaShape;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MembershipOrganizationForeignKeySchemaListener::class)]
final class MembershipOrganizationForeignKeySchemaListenerTest extends TestCase
{
    private const string FOREIGN_KEY = 'fk_membership_organization';

    /**
     * The backing index DBAL derives from the table+column hash when nothing else covers the column. The
     * mapping declares {@see self::MAPPED_INDEX} over it, which fulfils the FK and suppresses this one.
     */
    private const string BACKING_INDEX = 'IDX_86FFD28532C8A3DE';

    private const string MAPPED_INDEX = 'idx_membership_organization_id';

    #[Test]
    public function itReinjectsTheMembershipToOrganizationForeignKey(): void
    {
        $args = $this->args($this->schemaWithBothTables(Schema::editor()));

        $this->listener()->postGenerateSchema($args);

        $this->assertForeignKeyAndBackingIndex($args->getSchema()->getTable('membership'));
    }

    #[Test]
    public function itReinjectsTheForeignKeyIntoASchemaCarryingADefaultNamespace(): void
    {
        $args = $this->args($this->schemaWithBothTables(Schema::editor()->setDefaultNamespace('public')));

        $this->listener()->postGenerateSchema($args);

        $schema = $args->getSchema();
        $this->assertTrue($schema->hasTable('public.membership'));
        $this->assertForeignKeyAndBackingIndex($schema->getTable('membership'));
    }

    #[Test]
    public function itPreservesEverythingElseInTheSchema(): void
    {
        $membership = SchemaFixture::table('membership', 'organization_id', 'user_id')
            ->edit()
            ->addIndex(SchemaFixture::index(self::MAPPED_INDEX, 'organization_id'))
            ->addIndex(SchemaFixture::index('idx_membership_user_id', 'user_id'))
            ->create()
        ;
        $invitation = SchemaFixture::table('invitation', 'id', 'organization_id')
            ->edit()
            ->addIndex(SchemaFixture::index('invitation_organization_idx', 'organization_id'))
            ->addForeignKeyConstraint(
                SchemaFixture::foreignKey('invitation_organization_fk', 'organization_id', 'organization'),
            )
            ->create()
        ;
        $args = $this->args(
            Schema::editor()
                ->addTable(SchemaFixture::table('organization', 'id'))
                ->addTable($membership)
                ->addTable($invitation)
                ->addSequence(SchemaFixture::sequence('invoice_number_seq', 10))
                ->create(),
        );

        $this->listener()->postGenerateSchema($args);

        $schema = $args->getSchema();
        $table = $schema->getTable('membership');
        $this->assertForeignKey($table);
        $this->assertSame(
            [
                self::MAPPED_INDEX => SchemaShape::regularIndex('organization_id'),
                'idx_membership_user_id' => SchemaShape::regularIndex('user_id'),
            ],
            SchemaShape::indexesOf($table),
            'the mapped index fulfils the foreign key, so no backing index is added beside it',
        );
        $this->assertTrue($table->hasColumn('user_id'));
        $this->assertTrue($schema->hasTable('organization'));
        $invitation = $schema->getTable('invitation');
        $this->assertSame(
            ['invitation_organization_idx' => SchemaShape::regularIndex('organization_id')],
            SchemaShape::indexesOf($invitation),
        );
        $this->assertSame(['invitation_organization_fk'], \array_keys($invitation->getForeignKeys()));
        $foreignKey = $invitation->getForeignKey('invitation_organization_fk');
        $this->assertSame(['organization_id'], SchemaShape::names($foreignKey->getReferencingColumnNames()));
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
        $this->assertCount(1, $injected->getTable('membership')->getForeignKeys());
    }

    #[Test]
    public function itDoesNothingWhenTheReferencedTableIsAbsent(): void
    {
        $schema = Schema::editor()->addTable(SchemaFixture::table('membership', 'organization_id'))->create();
        $args = $this->args($schema);

        $this->listener()->postGenerateSchema($args);

        $this->assertSame($schema, $args->getSchema());
        $this->assertFalse($schema->getTable('membership')->hasForeignKey(self::FOREIGN_KEY));
    }

    private function assertForeignKeyAndBackingIndex(Table $table): void
    {
        $this->assertForeignKey($table);
        $this->assertSame(
            [self::BACKING_INDEX => SchemaShape::regularIndex('organization_id')],
            SchemaShape::indexesOf($table),
        );
    }

    private function assertForeignKey(Table $table): void
    {
        $this->assertSame([self::FOREIGN_KEY], \array_keys($table->getForeignKeys()));
        $foreignKey = $table->getForeignKey(self::FOREIGN_KEY);
        $this->assertSame(['organization_id'], SchemaShape::names($foreignKey->getReferencingColumnNames()));
        $this->assertSame('organization', $foreignKey->getReferencedTableName()->toString());
        $this->assertSame(['id'], SchemaShape::names($foreignKey->getReferencedColumnNames()));
    }

    private function schemaWithBothTables(SchemaEditor $editor): Schema
    {
        return $editor
            ->addTable(SchemaFixture::table('organization', 'id'))
            ->addTable(SchemaFixture::table('membership', 'organization_id'))
            ->create()
        ;
    }

    private function listener(): MembershipOrganizationForeignKeySchemaListener
    {
        return new MembershipOrganizationForeignKeySchemaListener();
    }

    private function args(Schema $schema): GenerateSchemaEventArgs
    {
        return new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema);
    }
}
