<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine\MembershipOrganizationForeignKeySchemaListener;
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

    #[Test]
    public function itReinjectsTheMembershipToOrganizationForeignKey(): void
    {
        $schema = $this->schemaWithBothTables();

        $this->listener()->postGenerateSchema($this->args($schema));

        $this->assertTrue($schema->getTable('membership')->hasForeignKey(self::FOREIGN_KEY));
    }

    #[Test]
    public function itIsIdempotentWhenTheForeignKeyAlreadyExists(): void
    {
        $schema = $this->schemaWithBothTables();
        $listener = $this->listener();
        $args = $this->args($schema);

        $listener->postGenerateSchema($args);
        $listener->postGenerateSchema($args);

        $this->assertCount(1, $schema->getTable('membership')->getForeignKeys());
    }

    #[Test]
    public function itDoesNothingWhenTheReferencedTableIsAbsent(): void
    {
        $schema = new Schema();
        $membership = $schema->createTable('membership');
        $membership->addColumn('organization_id', Types::GUID);

        $this->listener()->postGenerateSchema($this->args($schema));

        $this->assertFalse($schema->getTable('membership')->hasForeignKey(self::FOREIGN_KEY));
    }

    private function schemaWithBothTables(): Schema
    {
        $schema = new Schema();

        $organization = $schema->createTable('organization');
        $organization->addColumn('id', Types::GUID);

        $membership = $schema->createTable('membership');
        $membership->addColumn('organization_id', Types::GUID);

        return $schema;
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
