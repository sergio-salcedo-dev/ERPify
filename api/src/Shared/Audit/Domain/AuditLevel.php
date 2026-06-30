<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The level of an audit record, which also fixes how the record is written: `activity`
 * asynchronously, `security` synchronously before the response is sent, and `change`
 * synchronously inside the writing transaction (Doctrine `onFlush` change-data-capture), so a
 * change row commits atomically with the business write it describes or not at all. That
 * write-mode branching lives in the writers, not here, so the enum stays case-only. The backing
 * values are the lowercase tokens persisted verbatim in the `level` column, so the enum doubles
 * as the closed set the storage layer round-trips — lowercase, like the sibling {@see ActorType}
 * and unlike the uppercase wire enums whose tokens are a transport contract.
 */
enum AuditLevel: string
{
    case ACTIVITY = 'activity';
    case SECURITY = 'security';
    case CHANGE = 'change';
}
