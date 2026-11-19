<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Query;

use Erpify\Shared\Search\Domain\SearchCriteria;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The wire query of a series page, bound by `#[MapQueryString]`: a malformed value is a 422 `validation-failed` before
 * any lookup. `afterId` is the last version the caller holds (absent reads from the first); `limit` is 1 to the wire
 * maximum, the shared pagination policy's default when absent.
 */
final readonly class DocumentSeriesQuery
{
    public function __construct(
        #[Assert\Uuid(message: 'The afterId must be a UUID.')]
        public ?string $afterId = null,
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(SearchCriteria::MAX_LIMIT)]
        public ?int $limit = null,
    ) {
    }
}
