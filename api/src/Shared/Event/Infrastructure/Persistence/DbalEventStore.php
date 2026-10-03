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
use Erpify\Shared\Event\Domain\Exception\CorruptEventStoreRow;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link EventStore} on the permanent `event_store` table — append-only, with a closed set of sanctioned
 * mutations, of which the GDPR erasure ({@see DbalEventStoreSubjectAnonymiser}) is the only one today — via
 * plain DBAL on the **default**
 * connection, so {@see append()} joins the use-case write transaction (aggregate row + this row +
 * outbox commit atomically). It is a log of infrastructure with no domain invariants and no ORM
 * entity — the `IDENTITY` sequence and the `aggregate_version` sub-select sit outside the ORM unit of
 * work (ADR D4). `tenant_id` is reserved and written `NULL`.
 *
 * `aggregate_version` is an **informative** per-stream counter, not a concurrency control (ADR D14). The
 * stream UNIQUE spans `tenant_id`, which is always `NULL` here, and PostgreSQL compares nulls as distinct,
 * so it refuses nothing; and most publishers hold no row lock on the aggregate they publish for. Two
 * concurrent appends to one stream can therefore record the same version, both commit, and nothing is
 * raised. Order is `sequence`, never this column.
 *
 * `metadata` carries the serializer's envelope metadata (empty today) and is cast to an object before
 * encoding, so an empty envelope stores `{}` rather than `[]` — the same top-level cast, for the same reason,
 * as `audit_log` ({@see \Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter}).
 */
#[AsAlias(EventStore::class)]
final readonly class DbalEventStore implements EventStore
{
    private const int JSON_MAX_DEPTH = 512;

    public function __construct(
        private Connection $connection,
        private DomainEventSerializer $serializer,
    ) {
    }

    #[Override]
    public function append(DomainEvent $event): void
    {
        $envelope = $this->serializer->serialize($event);

        // aggregate_version = MAX(version)+1 per (tenant_id, aggregate_id). The read takes no lock of its
        // own, so a concurrent append to the same stream may compute the same value (class docblock).
        // `sequence`/identity is assigned by the database.
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
            . 'CAST(:occurred_on AS TIMESTAMPTZ), clock_timestamp() '
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

        if (!\is_numeric($value)) {
            throw CorruptEventStoreRow::nonNumericColumn($key);
        }

        return (int) $value;
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
     * Both columns hold a map, and a map has one JSON shape: PHP's empty array would otherwise encode as `[]`.
     * Only the top level is cast, so a list nested inside a value stays a list.
     *
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return \json_encode((object) $data, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (!\is_string($json)) {
            return [];
        }

        $decoded = \json_decode($json, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);

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
