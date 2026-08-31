<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Minting refused because the identity already holds a live recovery secret. A {@see Conflict} marker → 409,
 * with a `type()` of its own so the client can offer the one action that resolves it — revoke, then mint again
 * — rather than reading a generic `conflict` and guessing.
 *
 * Refusing is the whole point: silently superseding would destroy, without telling anyone, a credential whose
 * holder may have written it down and stored it away from the machine, which is exactly the invisible
 * destruction this channel is designed never to perform.
 *
 * It answers BOTH the checked case and the raced one. The check reads the row under `SELECT … FOR UPDATE`, and
 * the unique index on `user_id` is what makes that decision binding rather than advisory — so
 * {@see \Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineRecoverySecretRepository::save()}
 * translates the constraint violation into this same exception. Two mints racing therefore produce one 201 and
 * one 409, never a 500 for whichever lost, and the caller has one outcome to handle instead of two spellings
 * of one situation. That is the specific answer a module may give in place of the generic
 * {@see \Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite}.
 */
final class RecoverySecretAlreadyExists extends DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct(
            type: 'recovery-secret-already-exists',
            title: 'This account already has a recovery secret. Revoke it before minting a new one.',
        );
    }
}
