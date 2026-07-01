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
     * @param ?string              $id       the entry id to adopt when the caller minted it up front (e.g. to
     *                                       seal a value under it before the row exists); minted here when null
     */
    public function create(
        string $action,
        AuditLevel $level,
        ?AuditResource $resource = null,
        array $metadata = [],
        ?string $encryptionScopeId = null,
        ?string $id = null,
    ): AuditLogEntry;

    /**
     * Mints an entry id up front, for a caller that must know it before the entry exists — e.g. to seal a
     * value under the id it will be stored on. Feed the returned id back into {@see create()} as `$id`.
     */
    public function mintId(): string;
}
