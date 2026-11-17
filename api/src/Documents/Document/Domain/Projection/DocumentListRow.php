<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Projection;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\DocumentState;

/**
 * One document as the listing reads it: a query result of a DQL `SELECT NEW` over the snapshot, never the aggregate.
 * It lives in `Domain/` because the read port returns it, like the other read-side projections of this codebase.
 *
 * It carries what the wire shows PLUS what the listing needs and the wire never shows: `scopeId` and
 * `filenameCiphertext` are the inputs of the name opener, and `createdAt` is the keyset column the engine reads off
 * each row by bare property path. The uploader is not here at all. Never serialised: the resource mapper picks fields.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class DocumentListRow
{
    public function __construct(
        public string $id,
        public string $seriesId,
        public ?string $predecessorId,
        public DocumentState $state,
        public string $digest,
        public int $sizeBytes,
        public string $scopeId,
        public ?string $filenameCiphertext,
        public ?string $retentionReason,
        public ?string $legalBasis,
        public ?DateTimeImmutable $reviewAt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
