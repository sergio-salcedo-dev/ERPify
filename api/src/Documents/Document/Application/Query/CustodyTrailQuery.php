<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Query;

use Erpify\Shared\Search\Domain\SearchCriteria;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The wire query of a custody trail page, bound by `#[MapQueryString]`: a malformed or out-of-range value is a 422
 * `validation-failed` before any lookup.
 *
 * `afterSeq` is the last `seq` the caller already holds (absent reads from the start and returns the document's
 * metadata with the first page); it stops at the largest value the `integer` column of `seq` can hold, because a
 * larger literal would reach the database as an out-of-range comparison and answer 500. `limit` is the page size,
 * 1 to the wire maximum, the shared pagination policy's default when absent.
 */
final readonly class CustodyTrailQuery
{
    private const int MAX_SEQ = 2_147_483_647;

    public function __construct(
        #[Assert\PositiveOrZero]
        #[Assert\LessThanOrEqual(self::MAX_SEQ)]
        public ?int $afterSeq = null,
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(SearchCriteria::MAX_LIMIT)]
        public ?int $limit = null,
    ) {
    }
}
