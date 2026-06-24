<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain\Exception;

use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when an id-bearing actor (`user` / `api_key`) is minted with an id that is
 * not a well-formed RFC 4122 UUID.
 *
 * Deliberately marker-less: this is a server-side wiring fault in the actor factory,
 * not bad client input. The audit dispatch that mints the context is best-effort and
 * isolated, so the failure never reaches the RFC 9457 pipeline as a 5xx — leaving it
 * unmarked keeps it an actionable error in Sentry instead of masquerading as a
 * suppressed client error.
 *
 * Only the actor type travels in `context`; the offending id is never carried in clear.
 */
final class InvalidActorContext extends DomainException
{
    public const string TYPE = 'invalid-actor-context';

    public static function actorIdMustBeUuid(ActorType $type): self
    {
        return new self(
            type: self::TYPE,
            title: 'Actor id must be a valid UUID.',
            context: ['actorType' => $type->value],
        );
    }
}
