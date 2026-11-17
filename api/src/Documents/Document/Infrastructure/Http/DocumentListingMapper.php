<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Documents\Document\Application\Listing\CustodyTrail;
use Erpify\Documents\Document\Application\Listing\ListedDocument;
use Erpify\Documents\Document\Application\Resource\DocumentCustodyPageResource;
use Erpify\Documents\Document\Application\Resource\DocumentCustodyResource;
use Erpify\Documents\Document\Application\Resource\DocumentListResource;
use Erpify\Documents\Document\Domain\Projection\CustodyTrailRow;
use Erpify\Shared\Search\Domain\Page;

/**
 * Maps the listing read models to their wire resources: picks the fields (the uploader, the scope and the ciphertext
 * of the row never reach a resource) and owns the ATOM spelling of every timestamp.
 */
final readonly class DocumentListingMapper
{
    /**
     * @param Page<ListedDocument> $page
     *
     * @return Page<DocumentListResource>
     */
    public function toListPage(Page $page): Page
    {
        return new Page(
            \array_map($this->toListResource(...), $page->items),
            $page->hasNext,
            $page->hasPrev,
            $page->count,
            $page->nextCursor,
            $page->prevCursor,
        );
    }

    public function toListResource(ListedDocument $listed): DocumentListResource
    {
        $row = $listed->row;

        return new DocumentListResource(
            $row->id,
            $row->seriesId,
            $row->predecessorId,
            $row->state->value,
            $row->digest,
            $row->sizeBytes,
            $listed->name,
            $row->retentionReason,
            $row->legalBasis,
            $this->atom($row->reviewAt),
            $row->createdAt->format(DateTimeInterface::ATOM),
        );
    }

    public function toTrailResource(CustodyTrail $trail): DocumentCustodyPageResource|DocumentCustodyResource
    {
        $entries = \array_map($this->toEntry(...), $trail->entries);
        $listed = $trail->document;

        if (!$listed instanceof ListedDocument) {
            return new DocumentCustodyPageResource($entries, $trail->nextAfterSeq);
        }

        $row = $listed->row;

        return new DocumentCustodyResource(
            $row->id,
            $row->seriesId,
            $row->predecessorId,
            $row->state->value,
            $row->digest,
            $row->sizeBytes,
            $listed->name,
            $row->retentionReason,
            $row->legalBasis,
            $this->atom($row->reviewAt),
            $row->createdAt->format(DateTimeInterface::ATOM),
            $entries,
            $trail->nextAfterSeq,
        );
    }

    /**
     * @return array{seq: int, event: string, actorId: ?string, reason: ?string, occurredAt: string}
     */
    private function toEntry(CustodyTrailRow $row): array
    {
        return [
            'seq' => $row->seq,
            'event' => $row->event->value,
            'actorId' => $row->actorId,
            'reason' => $row->reason,
            'occurredAt' => $row->createdAt->format(DateTimeInterface::ATOM),
        ];
    }

    private function atom(?DateTimeImmutable $at): ?string
    {
        return $at?->format(DateTimeInterface::ATOM);
    }
}
