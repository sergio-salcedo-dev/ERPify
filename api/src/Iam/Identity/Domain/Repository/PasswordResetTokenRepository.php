<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;

/**
 * Aggregate-lifecycle port for {@see PasswordResetToken}.
 *
 * {@see findById()} is the selector half of the selector-verifier link (`<id>.<secret>`): the id picks the row
 * and the caller then verifies the secret against its digest, so no hash-index lookup is needed.
 * {@see deleteAllForUser()} supersedes a user's pending request when a fresh one is issued, and {@see consume()}
 * is the retire in the consumer's retire-then-act — a conditional delete whose affected-row count is the
 * single-use guard.
 */
interface PasswordResetTokenRepository
{
    public function save(PasswordResetToken $token): void;

    /**
     * Consumes the token as the retire in retire-then-act: a conditional delete that returns whether a row was
     * actually removed. It is the single-use guard — two concurrent completions serialise on the row lock, so
     * only the first deletes a row (returns `true`) and the loser sees it already gone (returns `false`) and
     * must abort. A token can therefore drive at most one reset even under a concurrent replay.
     */
    public function consume(PasswordResetToken $token): bool;

    /**
     * Returns the token by id, or `null` when no such row exists — including when `$id` is not a well-formed
     * UUID (a malformed selector can key no row), so the caller never has to pre-validate the selector shape.
     */
    public function findById(string $id): ?PasswordResetToken;

    /**
     * Drops every pending reset token of the user — called before issuing a new one so only the latest request
     * is live (a re-request supersedes its predecessor).
     */
    public function deleteAllForUser(string $userId): void;
}
