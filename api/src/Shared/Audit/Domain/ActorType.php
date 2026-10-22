<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * Who acted, as the discriminant sealed into every audit row. The backing values
 * are the lowercase tokens persisted verbatim in the `actor_type` column, so the
 * enum doubles as the closed set the storage layer round-trips — hence lowercase,
 * unlike the uppercase wire enums elsewhere whose tokens are a transport contract.
 *
 * Postgres enforces the same closed set, so adding a case also needs a migration replacing
 * `audit_log_actor_type_check` — and `audit_log_actor_id_presence_check` when the new case carries no id.
 */
enum ActorType: string
{
    case ANONYMOUS = 'anonymous';
    case SYSTEM = 'system';
    case API_KEY = 'api_key';
    case USER = 'user';
}
