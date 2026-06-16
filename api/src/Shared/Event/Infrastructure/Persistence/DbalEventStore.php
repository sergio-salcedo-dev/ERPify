<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Event\Application\DomainEventSerializer;
use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Application\StoredEvent;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link EventStore} on the permanent append-only `event_store` table via plain DBAL on the **default**
 * connection, so {@see append()} joins the use-case write transaction (aggregate row + this row +
 * outbox commit atomically). It is a log of infrastructure with no domain invariants and no ORM
 * entity — the `IDENTITY` sequence and the `aggregate_version` sub-select sit outside the ORM unit of
 * work (ADR D4). `tenant_id`/`metadata` are reserved and written as `NULL`/`{}` today.
 */
#[AsAlias(EventStore::class)]
final readonly class DbalEventStore implements EventStore
{
    private const int STREAM_COLUMNS_LIMIT = 512;

    public function __construct(
        private Connection $connection,
        private DomainEventSerializer $serializer,
    ) {
    }

    #[Override]
    public function append(DomainEvent $event): void
    {
        $envelope = $this->serializer->serialize($event);

        // aggregate_version = MAX(version)+1 per (tenant_id, aggregate_id), serialised by the row lock
        // the write transaction already holds on the aggregate; the UNIQUE on the stream makes it
        // optimistic concurrency control. `sequence`/identity is assigned by the database.
        //
        // ON CONFLICT (event_id) DO NOTHING makes a re-append a silent no-op: the persist middleware
        // also runs when the worker re-dispatches the message on consume, and at-least-once redelivery
        // can replay it — neither must write a second row nor abort the surrounding transaction.
        $this->connection->executeStatement(
            'INSERT INTO event_store '
            . '(event_id, aggregate_id, aggregate_type, aggregate_version, event_name, event_version, '
            . 'payload, metadata, tenant_id, occurred_on, recorded_on) '
            . 'SELECT CAST(:event_id AS UUID), CAST(:aggregate_id AS UUID), :aggregate_type, '
            . 'COALESCE(MAX(aggregate_version), 0) + 1, :event_name, CAST(:event_version AS SMALLINT), '
            . 'CAST(:payload AS JSONB), CAST(:metadata AS JSONB), CAST(:tenant_id AS UUID), '
            . 'CAST(:occurred_on AS TIMESTAMPTZ), now() '
            . 'FROM event_store '
            . 'WHERE aggregate_id = CAST(:aggregate_id AS UUID) '
            . 'AND tenant_id IS NOT DISTINCT FROM CAST(:tenant_id AS UUID) '
            . 'ON CONFLICT (event_id) DO NOTHING',
            [
                'event_id' => $event->eventId(),
                'aggregate_id' => $event->aggregateId(),
                'aggregate_type' => $event::aggregateType(),
                'event_name' => $event::eventName(),
                'event_version' => $event::eventVersion(),
                'payload' => $this->encode($envelope['payload']),
                'metadata' => $this->encode($envelope['metadata']),
                'tenant_id' => null,
                'occurred_on' => $event->occurredOn()->format('Y-m-d H:i:s.uP'),
            ],
        );
    }

    #[Override]
    public function stream(int $afterSequence, array $eventNames, int $limit): iterable
    {
        if ([] === $eventNames) {
            return;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT sequence, event_id, aggregate_id, aggregate_type, aggregate_version, event_name, '
            . 'event_version, payload, metadata, tenant_id, occurred_on, recorded_on '
            . 'FROM event_store '
            . 'WHERE sequence > :after AND event_name IN (:names) '
            . 'ORDER BY sequence ASC LIMIT :limit',
            ['after' => $afterSequence, 'names' => $eventNames, 'limit' => $limit],
            [
                'after' => Types::INTEGER,
                'names' => ArrayParameterType::STRING,
                'limit' => Types::INTEGER,
            ],
        );

        foreach ($rows as $row) {
            yield $this->toStoredEvent($row);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toStoredEvent(array $row): StoredEvent
    {
        return new StoredEvent(
            $this->intField($row, 'sequence'),
            $this->stringField($row, 'event_id'),
            $this->stringField($row, 'aggregate_id'),
            $this->stringField($row, 'aggregate_type'),
            $this->intField($row, 'aggregate_version'),
            $this->stringField($row, 'event_name'),
            $this->intField($row, 'event_version'),
            $this->decode($row['payload'] ?? null),
            $this->decode($row['metadata'] ?? null),
            $this->nullableStringField($row, 'tenant_id'),
            $this->stringField($row, 'occurred_on'),
            $this->stringField($row, 'recorded_on'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringField(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return \json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (!\is_string($json)) {
            return [];
        }

        $decoded = \json_decode($json, true, self::STREAM_COLUMNS_LIMIT, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            return [];
        }

        $result = [];

        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
