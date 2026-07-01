<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * Builds a fully sealed {@see AuditLogEntry} from what a call site legitimately names — action, level,
 * optional resource, metadata — minting the trusted parts (actor, correlation id, entry id, instant)
 * from sources the caller cannot forge. Keeping this out of {@see AuditLogger} separates "seal the
 * trusted context" (here) from "route the sealed entry by level" (the logger), so each is tested alone.
 */
interface AuditEntryFactory
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function create(
        string $action,
        AuditLevel $level,
        ?AuditResource $resource = null,
        array $metadata = [],
        ?string $encryptionScopeId = null,
    ): AuditLogEntry;
}
