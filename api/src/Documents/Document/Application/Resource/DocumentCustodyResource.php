<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of the FIRST page of a custody trail (`GET /documents/{id}/custody` with no `afterSeq`): the
 * document's metadata and opened name, then the page of rows.
 *
 * `entries` holds plain arrays of scalars — `seq`, `event`, `actorId`, `reason`, `occurredAt` — pre-built by the
 * mapper, the shape the resource contract admits for an already-serialised payload. An `actorId` is NULL for the
 * system, and the nil UUID for a person since erased: that value is a marker, never a user. `nextAfterSeq` is the
 * cursor of the next page and NULL on the last.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") the document's own wire fields beside its page; flat on purpose
 */
final readonly class DocumentCustodyResource
{
    /**
     * @param list<array{seq: int, event: string, actorId: ?string, reason: ?string, occurredAt: string}> $entries
     */
    public function __construct(
        public string $id,
        public string $seriesId,
        public ?string $predecessorId,
        public string $state,
        public string $digest,
        public int $sizeBytes,
        public ?string $name,
        public ?string $retentionReason,
        public ?string $legalBasis,
        public ?string $reviewAt,
        public string $createdAt,
        public array $entries,
        public ?int $nextAfterSeq,
    ) {
    }
}
