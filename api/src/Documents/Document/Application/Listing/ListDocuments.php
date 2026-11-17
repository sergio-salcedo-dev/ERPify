<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

use Erpify\Documents\Document\Domain\Projection\DocumentListRow;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;

/**
 * One keyset page of documents with their names opened. The names of the whole page cost ONE keystore query
 * ({@see DocumentNameOpener}), and an unopenable name is a null on its own row, never a failed page.
 *
 * Writes no audit row of its own: the listing is an ordinary read, recorded by the generic access log (the audit
 * boundary is the byte read, `ReadDocument`).
 */
final readonly class ListDocuments
{
    public function __construct(
        private DocumentListingRepository $documents,
        private DocumentNameOpener $nameOpener,
    ) {
    }

    /**
     * @return Page<ListedDocument>
     */
    public function list(SearchCriteria $criteria): Page
    {
        $page = $this->documents->search($criteria);
        $names = $this->nameOpener->open(\array_map(NameSource::fromRow(...), $page->items));

        return new Page(
            \array_map(
                static fn (DocumentListRow $row): ListedDocument => new ListedDocument(
                    $row,
                    ($names[$row->id] ?? OpenedName::absent())->name,
                ),
                $page->items,
            ),
            $page->hasNext,
            $page->hasPrev,
            $page->count,
            $page->nextCursor,
            $page->prevCursor,
        );
    }
}
