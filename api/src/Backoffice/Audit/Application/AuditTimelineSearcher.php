<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Application;

use Erpify\Backoffice\Audit\Application\Query\SearchAuditTimelineQuery;
use Erpify\Backoffice\Audit\Domain\AuditTimelineEntry;
use Erpify\Backoffice\Audit\Domain\Repository\AuditTimelineRepository;
use Erpify\Shared\Search\Domain\Page;

/**
 * Read-side handler for {@see SearchAuditTimelineQuery}. The timeline reads a single table, so there
 * is no enrichment step (unlike the bank list's account-count): this is the use-case boundary where
 * any future read policy (e.g. masking) would land, kept as a thin seam over the port.
 */
final readonly class AuditTimelineSearcher
{
    public function __construct(
        private AuditTimelineRepository $auditTimelineRepository,
    ) {
    }

    /**
     * @return Page<AuditTimelineEntry>
     */
    public function search(SearchAuditTimelineQuery $query): Page
    {
        return $this->auditTimelineRepository->search($query->criteria);
    }
}
