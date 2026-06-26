<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Domain\Repository;

use Erpify\Backoffice\Audit\Domain\AuditTimelineEntry;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;

/**
 * Read-side port over the append-only `audit_log` table: a keyset-paginated, filterable timeline
 * for investigation. There is deliberately no write counterpart here — Backoffice/Audit consumes
 * auditoría, the writer lives in `Shared/Audit`.
 *
 * Returns the keyset {@see Page} with OPAQUE cursors; link materialization is the responder's job.
 */
interface AuditTimelineRepository
{
    /** @return Page<AuditTimelineEntry> */
    public function search(SearchCriteria $criteria): Page;
}
