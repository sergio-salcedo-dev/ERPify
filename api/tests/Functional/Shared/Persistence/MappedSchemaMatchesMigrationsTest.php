<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Tests\Support\Persistence\DeployedDoctrineTransports;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\SchemaListener\MessengerTransportDoctrineSchemaListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

/**
 * Asks Doctrine for the migration it would write, and requires that there be none.
 *
 * The schema the ORM derives is the entity mapping PLUS whatever every `postGenerateSchema` listener adds —
 * the raw-DBAL tables (event store, audit log, keystore, checkpoints, the handled-event ledger, the bank
 * count), and the physical foreign keys across module boundaries, which the mapping deliberately does not
 * carry. `doctrine:schema:validate --skip-sync`, the only mapping check `php.lint.doctrine` runs, never
 * compares any of that with the migrated database. So a slip in a listener (an index that lost its
 * uniqueness, an index that went missing) or a column option dropped from an attribute (the `COLLATE "C"`
 * the keyset sort keys depend on) passes every other gate and first surfaces as a destructive statement in
 * the next `make db.diff`, where it reads like somebody else's change.
 *
 * **The one table the test kernel cannot declare on its own.** `messenger_messages` belongs to the Doctrine
 * Messenger transport: Symfony's {@see MessengerTransportDoctrineSchemaListener} adds it for every
 * {@see DoctrineTransport} in the container. The test environment swaps `async` and `failed` for
 * `in-memory://` (`when@test` in `config/packages/messenger.yaml`), so its container holds no Doctrine
 * transport, its listener adds nothing, and the comparison reads `DROP TABLE messenger_messages` against a
 * database the migrations built for the deployed configuration. Rather than exclude that name, the test
 * rebuilds the deployed transports from the same file — its base `transports:` block, which no environment
 * but `test` overrides (asserted) — through Symfony's own factory ({@see DeployedDoctrineTransports}), and
 * hands them to Symfony's own listener.
 * The table is therefore compared column by column like every other one, and its name is never written
 * here. The supplement is required to add something, so the day the test environment keeps a Doctrine
 * transport of its own this goes red and asks to be removed rather than going quietly redundant.
 *
 * What a green does not prove: that the migrations run on a fresh database in order (the suite's database
 * is prepared by `make db.test.prepare`, which is that proof), anything the comparator does not model —
 * triggers, functions, row-level policies, a `CHECK` constraint — nor anything about data.
 *
 * @internal
 */
#[CoversNothing]
final class MappedSchemaMatchesMigrationsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
    }

    #[Test]
    public function theMappingAndEverySchemaListenerDeclareExactlyTheMigratedSchema(): void
    {
        $this->seeTheDatabaseAsTheSchemaCommandsDo();

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $this->assertNotEmpty($metadata, 'The entity manager maps no entity, so the comparison would be vacuous.');

        $schemaTool = new SchemaTool($this->entityManager);
        $deployedTransports = DeployedDoctrineTransports::fromContainer(self::getContainer())->transports();

        $supplemented = DeployedDoctrineTransports::tablesAddedTo(
            $schemaTool->getSchemaFromMetadata($metadata),
            $this->entityManager->getConnection(),
            $deployedTransports,
        );
        $this->assertNotEmpty(
            $supplemented,
            'The deployed Doctrine Messenger transports add no table the test environment does not already '
            . 'declare, so the supplement in this test is redundant: remove it and compare the test '
            . "environment's schema on its own.",
        );

        $listener = new MessengerTransportDoctrineSchemaListener($deployedTransports);
        $eventManager = $this->entityManager->getEventManager();
        $eventManager->addEventListener(ToolEvents::postGenerateSchema, $listener);

        try {
            $statements = $schemaTool->getUpdateSchemaSql($metadata);
        } finally {
            $eventManager->removeEventListener(ToolEvents::postGenerateSchema, $listener);
        }

        $this->assertSame(
            [],
            $statements,
            "The mapping and the schema listeners no longer describe the migrated database. Doctrine would \n"
            . "migrate it with:\n\n  " . \implode(";\n  ", $statements) . ";\n\n"
            . 'If the database is right, fix the mapping attribute or the `postGenerateSchema` listener that '
            . 'declares the table. If the mapping is right, a migration is missing: `make db.diff`, then '
            . '`make db.test.prepare`.',
        );
    }

    /**
     * DoctrineMigrationsBundle hides its own bookkeeping table from the schema tools through an asset filter
     * that stays switched off until a `console.command` event names `doctrine:schema:update` or
     * `doctrine:schema:validate`; outside those commands `doctrine_migration_versions` reads as a table
     * nothing declares. Dispatching that event with the container's own update command switches on the
     * filter exactly as `doctrine:schema:update` runs under, rather than naming the table here.
     */
    private function seeTheDatabaseAsTheSchemaCommandsDo(): void
    {
        $command = self::getContainer()->get('doctrine.schema_update_command');
        $this->assertInstanceOf(Command::class, $command);

        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch(
            new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()),
            ConsoleEvents::COMMAND,
        );
    }
}
