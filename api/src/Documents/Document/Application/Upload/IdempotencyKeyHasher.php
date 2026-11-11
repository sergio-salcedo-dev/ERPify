<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

/**
 * Turns a caller's idempotency key into the actor-scoped hash the intent and the document store. Keyed, so a
 * stored hash cannot be matched against a guessed key, and scoped to the actor, so two callers choosing the
 * same key never collide and an erased actor's hash, once nulled, names nothing.
 */
interface IdempotencyKeyHasher
{
    /**
     * @return string 64 lower-case hexadecimal characters
     */
    public function hash(string $actorId, string $idempotencyKey): string;
}
