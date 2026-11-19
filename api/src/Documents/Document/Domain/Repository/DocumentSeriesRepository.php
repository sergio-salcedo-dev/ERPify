<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Repository;

use Erpify\Documents\Document\Domain\Projection\DocumentVersionRow;

/**
 * The read side of a version series: no lock, no aggregate. A version series is ordered by creation — `created_at`,
 * then the id (UUID v7) — never by chain position, so a gap an erasure left in the middle of a chain still renders in
 * its place.
 */
interface DocumentSeriesRepository
{
    /**
     * Up to `$limit` versions of the series in creation order, after the version `$afterId` when given; an `$afterId`
     * that is not a version of this series answers an empty page.
     *
     * @return list<DocumentVersionRow>
     */
    public function versionsOf(string $seriesId, ?string $afterId, int $limit): array;

    /**
     * The ids of the series' versions that are not `Erased`, newest first: the order a series erasure works in.
     *
     * @return list<string>
     */
    public function unerasedNewestFirst(string $seriesId): array;

    public function exists(string $seriesId): bool;
}
