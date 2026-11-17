<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Projection;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\CustodyEvent;

/**
 * One row of a document's custody trail as the read side returns it: the sequence number, the fact, who caused it
 * (NULL for the system; the erased-person marker, as stored, for a person since erased) and when. A query result,
 * never the entity.
 */
final readonly class CustodyTrailRow
{
    public function __construct(
        public int $seq,
        public CustodyEvent $event,
        public ?string $actorId,
        public ?string $reason,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
