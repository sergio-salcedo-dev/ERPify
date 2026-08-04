<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

/**
 * Schema-evolution seam. A reproducible store must be able to read an event whose payload shape
 * changed after it was written; schema evolution never rewrites the stored `payload`, it transforms it on
 * read. (The one statement that does write to that column is the GDPR erasure, a sanctioned mutation that
 * substitutes an identifier and leaves the shape untouched — nothing an upcaster has to know about.)
 * The chain is empty today ({@see \Erpify\Shared\Event\Infrastructure\Serialization\NullUpcaster}); an
 * implementation is added the first time an event's {@see \Erpify\Shared\Event\Domain\DomainEvent::eventVersion()}
 * is bumped.
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
