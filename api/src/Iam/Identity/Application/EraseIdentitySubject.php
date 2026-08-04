<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * GDPR "right to erasure" for an identity subject — the identity-context counterpart to
 * {@see \Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject} (kept per-module: each context
 * owns forgetting its own subject data). It hard-deletes the identity aggregate (the module's PII is the
 * user row itself — email and credential hash) AND every pending password-reset token, in one transaction,
 * so no credential-recovery artefact outlives the subject.
 *
 * It answers for this module's rows alone. A `user_id` held by another context carries no foreign key, so
 * nothing here cascades to it: each such reference is erased by the context that owns it, chained by
 * {@see FulfilIdentityErasure}, and the inventory of every persisted person identifier — with the file
 * obliged to erase each one — is `api/.person-reference-policy`.
 *
 * **It writes no compliance record, deliberately, and that is a safety property rather than an omission.**
 * The `GDPR_SUBJECT_ERASED` entry names the subject as its audit RESOURCE, so writing it means persisting the
 * person's real identifier a second time — and the only thing that removes that copy is the resource
 * anonymiser, which needs the pseudonym the actor pass mints and therefore cannot run from in here. A use case
 * that writes an identifier it cannot itself erase hands the obligation to whoever calls it, which is the
 * distributed obligation `api/.person-reference-policy` exists to make impossible to pass in silence. So the
 * entry is written by {@see FulfilIdentityErasure}, one statement before the anonymise that clears it, from
 * the same value. Invoke this class alone and the identity is still fully erased; what is missing is the
 * evidence that it happened, which a reviewer of that new call site sees. Idempotent: a re-run finds nothing
 * live and erases nothing.
 *
 * The asymmetry with {@see \Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject}, which does
 * self-audit, is not an inconsistency: that one is invoked on its own, so nobody else could record it.
 */
final readonly class EraseIdentitySubject
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetTokenRepository $resetTokens,
        private TransactionManager $transactionManager,
    ) {
    }

    public function execute(string $userId): IdentityErasureResult
    {
        Uuid::ensure($userId);

        return $this->transactionManager->transactional(function () use ($userId): IdentityErasureResult {
            $tokensDeleted = $this->resetTokens->deleteAllForUser($userId);

            $user = $this->users->findById($userId);
            $identityErased = $user instanceof User;

            if ($user instanceof User) {
                $this->users->remove($user);
            }

            return new IdentityErasureResult($userId, $identityErased, $tokensDeleted);
        });
    }
}
