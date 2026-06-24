<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * The single public write seam of the audit axis: one call records one action. A caller names only what
 * it legitimately knows — action, level, optional resource, metadata — and the adapter seals the actor,
 * correlation id, entry id and instant from trusted sources, so a module cannot forge who acted.
 *
 * Persistence branches on {@see AuditLevel} inside the adapter (activity asynchronous, security
 * write-before-send), and the failure boundary is asymmetric: an activity failure is swallowed so it
 * never sinks the use case, while a security failure propagates so a denial is never lost in silence.
 */
interface AuditLogger
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void;
}
