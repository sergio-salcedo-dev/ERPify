<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Repository;

use Erpify\Iam\Invitation\Domain\Entity\Invitation;

/**
 * Aggregate-lifecycle port for {@see Invitation}.
 *
 * The accept flow localises the invitation by its id — the emailed token is a `<invitationId>.<secret>`
 * selector-verifier, so the id selects the row and the aggregate's {@see Invitation::verify()} checks the
 * secret. That keeps the token digest opaque (the value object never exposes a lookup-by-hash) and needs no
 * secondary index on the hash.
 */
interface InvitationRepository
{
    public function save(Invitation $invitation): void;

    public function findById(string $id): ?Invitation;

    /**
     * Loads the invitation under a pessimistic write lock, so two concurrent accepts of the same single-use
     * token serialise on the row: the second caller blocks until the first commits, then re-reads the retired
     * (`ACCEPTED`) status and is rejected. A plain read cannot enforce single-use across two connections — the
     * status IS the gate, and it must be checked and flipped under a held lock. Must run inside a transaction.
     */
    public function findByIdForUpdate(string $id): ?Invitation;
}
