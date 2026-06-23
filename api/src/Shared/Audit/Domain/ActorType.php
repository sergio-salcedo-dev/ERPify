<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * Who acted, as the discriminant sealed into every audit row. The backing values
 * are the lowercase tokens persisted verbatim in the `actor_type` column, so the
 * enum doubles as the closed set the storage layer round-trips — hence lowercase,
 * unlike the uppercase wire enums elsewhere whose tokens are a transport contract.
 */
enum ActorType: string
{
    case ANONYMOUS = 'anonymous';
    case SYSTEM = 'system';
    case API_KEY = 'api_key';
    case USER = 'user';
}
