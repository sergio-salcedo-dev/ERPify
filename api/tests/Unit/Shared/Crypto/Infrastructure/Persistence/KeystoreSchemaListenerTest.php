<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Erpify\Shared\Crypto\Infrastructure\Persistence\KeystoreSchemaListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(KeystoreSchemaListener::class)]
final class KeystoreSchemaListenerTest extends TestCase
{
    #[Test]
    public function itInjectsTheKeystoreTable(): void
    {
        $schema = new Schema();

        (new KeystoreSchemaListener())->postGenerateSchema(new GenerateSchemaEventArgs(
            $this->createStub(EntityManagerInterface::class),
            $schema,
        ));

        $this->assertTrue($schema->hasTable('dek_keystore'));
        $table = $schema->getTable('dek_keystore');

        foreach (['encryption_scope_id', 'wrapped_dek', 'kek_version', 'created_at', 'destroyed_at'] as $column) {
            $this->assertTrue($table->hasColumn($column), \sprintf('expected column "%s"', $column));
        }

        $this->assertTrue($table->hasIndex('dek_keystore_destroyed_idx'));
        $this->assertSame(160, $table->getColumn('encryption_scope_id')->getLength());
        $this->assertFalse($table->getColumn('wrapped_dek')->getNotnull(), 'wrapped_dek is nullable once destroyed');
        $this->assertFalse($table->getColumn('destroyed_at')->getNotnull(), 'destroyed_at is nullable');
        $this->assertTrue($table->getColumn('kek_version')->getNotnull(), 'kek_version is not-null');
    }

    #[Test]
    public function itLeavesAnExistingTableUntouched(): void
    {
        $schema = new Schema();
        $listener = new KeystoreSchemaListener();
        $args = new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema);

        $listener->postGenerateSchema($args);
        $listener->postGenerateSchema($args);

        $this->assertTrue($schema->hasTable('dek_keystore'));
    }
}
