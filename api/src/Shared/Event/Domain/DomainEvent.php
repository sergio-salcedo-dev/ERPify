<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Domain;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\Exception\CorruptEventStoreRow;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Base type for domain events. The contract is hardened for a *reproducible* event store (not event
 * sourcing): an event can be reconstructed from its persisted row without minting new identity.
 *
 * `eventId`/`occurredOn` are injectable so replay, retries and tests preserve historical identity
 * instead of acquiring a fresh one. The canonical key of the store and the mapper is the
 * `(eventName, eventVersion)` pair, never the FQCN (refactor-fragile). See
 * docs/adr/event-store-and-projections.md.
 *
 * Every domain event in the app extends this single reproducible base by design — one flat root the mapper
 * scans, not a hierarchy to rebalance — so the child-count metric does not apply here; it grows with the
 * domain.
 *
 * @SuppressWarnings("PHPMD.NumberOfChildren")
 */
abstract class DomainEvent
{
    private readonly string $eventId;

    private readonly DateTimeImmutable $occurredOn;

    public function __construct(
        private readonly string $aggregateId,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        $this->eventId = $eventId ?? Uuid::generate();
        $this->occurredOn = $occurredOn ?? new DateTimeImmutable();
    }

    /**
     * Stable event key, `erpify.<context>.<aggregate>.<fact>`. Identifies the event across renames.
     */
    abstract public static function eventName(): string;

    /**
     * Stream family this event belongs to, e.g. `Backoffice.Bank`. Declared once per aggregate base.
     */
    abstract public static function aggregateType(): string;

    /**
     * Schema version of {@see toPrimitives()}; bumped when the payload shape changes (see Upcaster).
     */
    public static function eventVersion(): int
    {
        return 1;
    }

    /**
     * Domain data only — never the envelope (aggregateId/eventId/occurredOn live on the row).
     *
     * @return array<string, mixed>
     */
    abstract public function toPrimitives(): array;

    /**
     * Reconstructs the event from a stored row. `$occurredOn` is the ISO-8601 string form.
     *
     * @param array<string, mixed> $body
     */
    abstract public static function fromPrimitives(
        string $aggregateId,
        array $body,
        string $eventId,
        string $occurredOn,
    ): static;

    /**
     * Reads a payload member the current schema version promises is a string.
     *
     * The stored payload is written by {@see toPrimitives()}, so a member of the wrong type means the row no
     * longer matches the schema it was written under — corruption, a hand-edited row, or a version that should
     * have been upcast before reaching here. Replay must stop, and stop saying why: an `assert()` would state
     * the invariant without enforcing it, because production runs with `zend.assertions=-1`.
     *
     * @param array<string, mixed> $body
     *
     * @throws CorruptEventStoreRow when the member is absent or not a string
     */
    final protected static function stringMember(array $body, string $member): string
    {
        $value = $body[$member] ?? null;

        if (!\is_string($value)) {
            throw CorruptEventStoreRow::malformedPayloadMember(static::eventName(), $member, 'a string');
        }

        return $value;
    }

    /**
     * Reads a payload member the current schema version promises is a list. An absent member reads as empty:
     * a collection member that was never written is an empty collection, not corruption.
     *
     * @param array<string, mixed> $body
     *
     * @throws CorruptEventStoreRow when the member is present but not an array
     *
     * @return array<mixed>
     */
    final protected static function arrayMember(array $body, string $member): array
    {
        $value = $body[$member] ?? [];

        if (!\is_array($value)) {
            throw CorruptEventStoreRow::malformedPayloadMember(static::eventName(), $member, 'an array');
        }

        return $value;
    }

    final public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    final public function eventId(): string
    {
        return $this->eventId;
    }

    final public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }
}
