<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\DBAL\Connection;
use Erpify\Shared\Event\Application\ProjectionRunner;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;

/**
 * Asserts the permanent, append-only `event_store` (the log itself, not the transport outbox) via raw
 * SQL — it is a raw DBAL table with no ORM entity, so the generic entity steps cannot reach it. Also
 * drives projection rebuilds, for the reproducibility scenario (rebuild from the log == live count).
 */
final class EventStoreContext extends AbstractContext
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ProjectionRunner $projectionRunner,
    ) {
    }

    #[Then('there should be :count event stored named :eventName')]
    #[Then('there should be :count events stored named :eventName')]
    public function thereShouldBeEventsStoredNamed(int $count, string $eventName): void
    {
        $actual = $this->countStored('event_name = :name', ['name' => $eventName]);

        self::assertSame(
            $count,
            $actual,
            \sprintf('Expected %d "%s" event(s) in the store, found %d.', $count, $eventName, $actual),
        );
    }

    #[Then('there should be :count event stored for aggregate :aggregateId named :eventName')]
    #[Then('there should be :count events stored for aggregate :aggregateId named :eventName')]
    public function thereShouldBeEventsStoredForAggregate(int $count, string $aggregateId, string $eventName): void
    {
        $actual = $this->countStored(
            'aggregate_id = CAST(:id AS UUID) AND event_name = :name',
            ['id' => $aggregateId, 'name' => $eventName],
        );

        self::assertSame(
            $count,
            $actual,
            \sprintf(
                'Expected %d "%s" event(s) for aggregate %s, found %d.',
                $count,
                $eventName,
                $aggregateId,
                $actual,
            ),
        );
    }

    #[Given('the stored events are cleared')]
    public function theStoredEventsAreCleared(): void
    {
        $this->connection->executeStatement('DELETE FROM event_store');
    }

    #[Given('the :name projection is rebuilt')]
    #[When('I rebuild the :name projection')]
    public function theProjectionIsRebuilt(string $name): void
    {
        $this->projectionRunner->rebuild($name);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countStored(string $where, array $params): int
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM event_store WHERE ' . $where, $params);

        return \is_numeric($value) ? (int) $value : 0;
    }
}
