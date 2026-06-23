<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\Exception\InvalidActorContext;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Identifies who acted for an audit record: an {@see ActorType} discriminant and,
 * for id-bearing actors, the actor's UUID. Construction is closed behind named
 * factories so illegal states are unrepresentable — `anonymous`/`system` can never
 * carry an id, and `user`/`api_key` can never be built without a valid one — which
 * removes the `actor_id IS NULL` ambiguity forensic queries would otherwise inherit.
 */
final readonly class ActorContext
{
    private function __construct(
        public ActorType $type,
        public ?string $actorId,
    ) {
    }

    public static function anonymous(): self
    {
        return new self(ActorType::ANONYMOUS, null);
    }

    public static function system(): self
    {
        return new self(ActorType::SYSTEM, null);
    }

    public static function forUser(string $id): self
    {
        return self::withValidatedId(ActorType::USER, $id);
    }

    public static function forApiKey(string $id): self
    {
        return self::withValidatedId(ActorType::API_KEY, $id);
    }

    private static function withValidatedId(ActorType $type, string $id): self
    {
        if (!Uuid::isValid($id)) {
            throw InvalidActorContext::actorIdMustBeUuid($type);
        }

        return new self($type, $id);
    }
}
