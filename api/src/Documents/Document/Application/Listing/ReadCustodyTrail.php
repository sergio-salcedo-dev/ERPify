<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;
use Erpify\Shared\Search\Domain\SearchCriteria;

/**
 * One page of one document's custody trail, paged by a plain cursor on `seq`.
 *
 * The trail is not bounded (every manual re-scan appends a row), so it is paged rather than truncated: the cursor is
 * the last `seq` the caller holds, scoped to the one document, and the page carries the next one when a row beyond it
 * exists. The order is fixed and `(document_id, seq)` is already unique and indexed, which is why this is not the
 * multi-document keyset engine. A row appended between two requests shows up on a later page; no snapshot is promised.
 *
 * **The document's metadata and name ride the first page only** (no cursor given; an explicit `afterSeq=0` is a
 * cursor, not an absent one, and answers the later-page shape from `seq` 1), so walking a trail costs one name
 * open; a later page still answers 404 for an unknown document. An `Erased` document answers with its trail and no
 * name.
 */
final readonly class ReadCustodyTrail
{
    public function __construct(
        private DocumentListingRepository $documents,
        private DocumentCustodyRepository $custody,
        private DocumentNameOpener $nameOpener,
    ) {
    }

    /**
     * @param int|null $afterSeq the last `seq` already held; null reads the first page, with the metadata
     * @param int      $limit    the page size, already resolved by the wire pagination policy at the edge
     *
     * @throws DocumentNotFound when no document has this id
     */
    public function read(string $documentId, ?int $afterSeq, int $limit): CustodyTrail
    {
        $row = $this->documents->find($documentId) ?? throw DocumentNotFound::create();

        $limit = \max(1, \min($limit, SearchCriteria::MAX_LIMIT));
        // One more than the page: its presence is what says another page exists, and it is never returned.
        $fetched = $this->custody->trailAfter($row->id, \max(0, $afterSeq ?? 0), $limit + 1);
        $entries = \array_slice($fetched, 0, $limit);
        $last = [] === $entries ? null : \array_last($entries);
        $nextAfterSeq = \count($fetched) > $limit && null !== $last ? $last->seq : null;

        if (null !== $afterSeq) {
            return new CustodyTrail(null, $entries, $nextAfterSeq);
        }

        $name = $this->nameOpener->open([NameSource::fromRow($row)])[$row->id] ?? OpenedName::absent();

        return new CustodyTrail(new ListedDocument($row, $name->name), $entries, $nextAfterSeq);
    }
}
