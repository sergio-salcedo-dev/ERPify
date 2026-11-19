<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentSeriesRepository;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * One page of a version series: every version, `Erased` ones included as tombstones, in creation order, paged by the
 * last version the caller holds. The series is named by its id, which is its first version's id.
 *
 * It opens no name and reads no byte, so it needs no keystore; an `Erased` version answers its tombstone fields and no
 * uploader. A version filed between two requests appears on a later page; no snapshot is promised.
 */
final readonly class ReadDocumentSeries
{
    public function __construct(
        private DocumentSeriesRepository $series,
    ) {
    }

    /**
     * @param string|null $afterId the last version already held; null reads from the first version
     * @param int         $limit   the page size, already resolved by the wire pagination policy at the edge
     *
     * @throws DocumentNotFound when no document belongs to the series
     */
    public function read(string $seriesId, ?string $afterId, int $limit): DocumentSeriesPage
    {
        Uuid::ensure($seriesId);
        $seriesId = \strtolower($seriesId);

        if (!$this->series->exists($seriesId)) {
            throw DocumentNotFound::create();
        }

        $limit = \max(1, \min($limit, SearchCriteria::MAX_LIMIT));
        // One more than the page: its presence is what says another page exists, and it is never returned.
        $fetched = $this->series->versionsOf($seriesId, $afterId, $limit + 1);
        $versions = \array_slice($fetched, 0, $limit);
        $last = [] === $versions ? null : \array_last($versions);

        return new DocumentSeriesPage(
            $seriesId,
            $versions,
            \count($fetched) > $limit && null !== $last ? $last->id : null,
        );
    }
}
