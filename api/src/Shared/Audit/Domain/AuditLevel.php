<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The severity axis of an audit record. The two cases also decide how the record is
 * written — `activity` async, `security` before the response is sent — but that branching
 * lives in the writer, not here, so the enum stays case-only. The backing values are the
 * lowercase tokens persisted verbatim in the `level` column, so the enum doubles as the
 * closed set the storage layer round-trips — lowercase, like the sibling {@see ActorType}
 * and unlike the uppercase wire enums whose tokens are a transport contract.
 */
enum AuditLevel: string
{
    case ACTIVITY = 'activity';
    case SECURITY = 'security';
}
