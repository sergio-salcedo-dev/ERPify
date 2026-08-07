<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

/**
 * Schema-evolution seam. A reproducible store must be able to read an event whose payload shape
 * changed after it was written; schema evolution never rewrites the stored `payload`, it transforms it on
 * read. (The one statement that does write to that column is the GDPR erasure, a sanctioned mutation that
 * substitutes an identifier and leaves the shape untouched — nothing an upcaster has to know about.)
 * The chain is empty ({@see \Erpify\Shared\Event\Infrastructure\Serialization\NullUpcaster}). An implementation
 * is added when a payload gains, loses or renames a field. It is **not** the answer to every bump of
 * {@see \Erpify\Shared\Event\Domain\DomainEvent::eventVersion()}: the six `Iam.Invitation` events sit at v2
 * because the meaning of their `aggregate_id` changed, and this seam only ever sees the payload — an upcaster
 * there would have been a half-migration with the shape of a guarantee, so their v1 rows are left to fail loudly
 * on read instead.
 */
interface Upcaster
{
    /**
     * Transforms `$payload` one or more versions forward, returning the payload at {@see targetVersion()}.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function upcast(string $eventName, int $fromVersion, array $payload): array;

    /**
     * The current schema version the payload of `$eventName` is upcast to.
     */
    public function targetVersion(string $eventName, int $fromVersion): int;
}
