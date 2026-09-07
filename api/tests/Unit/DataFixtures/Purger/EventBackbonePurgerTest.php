<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\DataFixtures\Purger;

use Doctrine\DBAL\Connection;
use Erpify\Tests\DataFixtures\Purger\EventBackbonePurger;
use Erpify\Tests\DataFixtures\Purger\EventBackbonePurgerFactory;
use Fidry\AliceDataFixtures\Persistence\PurgeMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The purger's failure mode is silence: a table missing from the statement truncates nothing and throws
 * nothing, and the first symptom is a projected total nobody can tell is wrong. Nothing else in the
 * suite asserts its effect — the fixture load exercises it incidentally and asserts nothing about it.
 *
 * @internal
 */
#[CoversClass(EventBackbonePurger::class)]
#[CoversClass(EventBackbonePurgerFactory::class)]
final class EventBackbonePurgerTest extends TestCase
{
    public function testItPurgesThroughTheInnerPurgerBeforeTouchingTheBackbone(): void
    {
        $log = new PurgeCallLog();

        (new EventBackbonePurger(new RecordingPurger($log), $this->connectionRecording($log), []))->purge();

        // The ORM purge first: it is what the caller asked for, and the backbone reset is the extension.
        $this->assertSame(['inner-purge', 'truncate'], $log->entries());
    }

    /**
     * The statement is asserted by NAME rather than as a whole literal: a literal comparison is a
     * tautology against the constant it copies, and what matters is that each table is in it.
     */
    public function testItTruncatesEveryBackboneTable(): void
    {
        $log = new PurgeCallLog();
        $statement = null;
        $connection = $this->connectionRecording($log, $statement);

        (new EventBackbonePurger(new RecordingPurger($log), $connection, []))->purge();

        foreach (['event_store', 'projection_checkpoint', 'handled_domain_event', 'messenger_messages'] as $table) {
            $this->assertStringContainsString($table, (string) $statement);
        }
    }

    /**
     * Read models are reset through the projectors, so a projector registered tomorrow is covered by
     * being registered. A table name here would have to be remembered instead.
     */
    public function testItResetsEveryRegisteredProjector(): void
    {
        $log = new PurgeCallLog();
        $projectors = [new RecordingProjector('first', $log), new RecordingProjector('second', $log)];

        (new EventBackbonePurger(new RecordingPurger($log), $this->connectionRecording($log), $projectors))
            ->purge()
        ;

        $this->assertSame(['inner-purge', 'truncate', 'reset:first', 'reset:second'], $log->entries());
    }

    public function testTheFactoryWrapsThePurgerItsInnerFactoryBuilt(): void
    {
        $log = new PurgeCallLog();
        $factory = new EventBackbonePurgerFactory(
            new FixedPurgerFactory(new RecordingPurger($log)),
            $this->connectionRecording($log),
            [],
        );

        $purger = $factory->create(PurgeMode::createTruncateMode());

        $this->assertInstanceOf(EventBackbonePurger::class, $purger);
        $purger->purge();
        $this->assertSame(['inner-purge', 'truncate'], $log->entries());
    }

    /**
     * Truncating the event log while the caller asked for no purge at all would be wrong on the
     * decorator's own terms, whatever the current caller happens to do.
     */
    public function testTheFactoryPassesANoPurgeModeStraightThrough(): void
    {
        $log = new PurgeCallLog();
        $inner = new RecordingPurger($log);
        $factory = new EventBackbonePurgerFactory(
            new FixedPurgerFactory($inner),
            $this->connectionRecording($log),
            [],
        );

        $purger = $factory->create(PurgeMode::createNoPurgeMode());

        $this->assertSame($inner, $purger);
        $purger->purge();
        $this->assertSame(['inner-purge'], $log->entries());
    }

    private function connectionRecording(PurgeCallLog $log, ?string &$statement = null): Connection
    {
        // A stub rather than a mock: the assertions are about the order and the statement text the
        // subject produced, never about call counts on a doubled collaborator.
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use ($log, &$statement): int {
                $log->record('truncate');
                $statement = $sql;

                return 0;
            })
        ;

        return $connection;
    }
}
