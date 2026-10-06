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
    /**
     * Whether an actor of this type is named by an id. `anonymous` and `system` name nobody, so they never
     * carry one; an `api_key` or a `user` is nothing without it. This is the rule {@see ActorContext}'s
     * factories encode and the `audit_log` CHECK constraints store, so the storage side derives its token
     * lists from here rather than restating them.
     */
    public function isIdentified(): bool
    {
        return match ($this) {
            self::ANONYMOUS, self::SYSTEM => false,
            self::API_KEY, self::USER => true,
        };
    }

    case ANONYMOUS = 'anonymous';
    case SYSTEM = 'system';
    case API_KEY = 'api_key';
    case USER = 'user';
}
