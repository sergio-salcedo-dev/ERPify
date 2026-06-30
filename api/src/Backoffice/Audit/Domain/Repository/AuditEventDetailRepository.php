<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Domain\Repository;

use Erpify\Backoffice\Audit\Domain\AuditEventDetail;

/**
 * Read-side port for a single audit event by id — the sibling of {@see AuditTimelineRepository},
 * split off (ISP) so the keyset listing contract stays untouched and the hot paginated path never
 * carries the per-row JSONB diff. Returns null when no row matches; turning that into a 404 is the
 * caller's policy, not the adapter's. The same DBAL adapter implements both ports.
 */
interface AuditEventDetailRepository
{
    public function findById(string $id): ?AuditEventDetail;
}
