<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Application\Query;

use Erpify\Shared\Search\Domain\SearchCriteria;

/**
 * Read-side query for the audit investigation timeline. Carries the generic {@see SearchCriteria}
 * resolved from the shared HTTP {@see \Erpify\Shared\Search\Application\Http\SearchQuery}; its
 * handler is {@see \Erpify\Backoffice\Audit\Application\AuditTimelineSearcher}.
 */
final readonly class SearchAuditTimelineQuery
{
    public function __construct(public SearchCriteria $criteria)
    {
    }
}
