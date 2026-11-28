<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Iam\Identity\Domain\Repository\TotpFactorRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * GDPR "right to erasure" for an identity subject — the identity-context counterpart to
 * {@see \Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject} (kept per-module: each context
 * owns forgetting its own subject data). It hard-deletes the identity aggregate (the module's PII is the
 * user row itself — email and credential hash), every pending password-reset token, the recovery secret AND the
 * second factor, in one transaction, so no credential artefact outlives the subject.
 *
 * The recovery secret is the one whose absence would be least visible and most durable: it has a ten-year
 * TTL and no retention sweep to age it out, so a `user_id` left behind here would outlive the erasure by a
 * decade with nothing scheduled to notice.
 *
 * The second factor is erased the way {@see \Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject}
 * erases a sealed subject: the row is deleted and its scope's key destroyed in the same transaction, so from that
 * commit on the sealed secret is unreadable in the live database. A backup taken before the erasure still holds the
 * row and its live key, and a restore to it brings the factor back with the identity (ADR
 * `two-step-verification.md` D4, `PRODUCTION_SECURITY_CHECKLIST.md` §7). The erased factor's scope is reported in
 * the result, because the `dek_keystore` tombstone must be answered by a `GDPR_SUBJECT_ERASED` entry carrying it,
 * or the subject-erasure reconciler reports a divergence for ever; {@see FulfilIdentityErasure} writes that entry.
 * The scope is the factor's own random id, so neither the tombstone nor that entry names the person.
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
        private RecoverySecretRepository $recoverySecrets,
        private TotpFactorRepository $totpFactors,
        private Keystore $keystore,
        private TransactionManager $transactionManager,
    ) {
    }

    public function execute(string $userId): IdentityErasureResult
    {
        Uuid::ensure($userId);

        return $this->transactionManager->transactional(function () use ($userId): IdentityErasureResult {
            // The identity row is taken before the tokens, and the order is the whole point rather than a
            // reading preference. Both reset paths acquire the same pair this way round, and neither is free
            // to stop: for the forgot path the user row lock IS the supersede mutex, so two concurrent
            // requests that took it after their delete+insert would leave both tokens live; for the complete
            // path the status re-read under that lock is what walls a reset an administrator has just
            // suspended. Their order is a correctness requirement, not a habit. This use case has no such
            // requirement — it holds the subject's id before the transaction opens and could acquire either
            // way — so it is the one that conforms.
            //
            // The recovery secret joins at the END, with one degree of freedom less than that suggests:
            // minting and redeeming both take `identity_user` before `identity_recovery_secret`, and
            // redemption cannot reorder — it learns which identity to lock only from the row its selector
            // resolves — so user-first is fixed on this table too. Its position relative to the reset tokens
            // is the part that is genuinely free, because no other path reaches both of those two.
            //
            // The second factor joins after it, then its keystore row: the enrolment and the confirmation take
            // `identity_user`, then `identity_totp_factor`, then the keystore row through the sealing, so this is
            // the one order the three agree on. No path takes the factor together with a reset token or a
            // recovery secret, so its position after those two is free.
            $user = $this->users->findById($userId);
            $identityErased = $user instanceof User;

            if ($user instanceof User) {
                $this->users->remove($user);
            }

            $tokensDeleted = $this->resetTokens->deleteAllForUser($userId);
            $recoverySecretsDeleted = $this->recoverySecrets->deleteAllForUser($userId);
            [$totpFactorsDeleted, $erasedScope] = $this->eraseTotpFactor($userId);

            return new IdentityErasureResult(
                $userId,
                $identityErased,
                $tokensDeleted,
                $recoverySecretsDeleted,
                $totpFactorsDeleted,
                $erasedScope,
            );
        });
    }

    /**
     * Deletes the subject's factor, then destroys the key its secret was sealed under.
     *
     * Load-bearing order, as in the bank-account erasure: the row is removed first (the remove flushes eagerly),
     * then the key is tombstoned in the same transaction, so the two commit or roll back together. The scope is
     * reported whenever a row was deleted, whether or not this call wrote the tombstone: a key found already
     * tombstoned has no evidence of its own guaranteed anywhere, and naming the scope twice costs nothing while
     * naming it never leaves the reconciler reporting the tombstone for ever.
     *
     * @return array{int, ?string} factors deleted, and the scope of the deleted factor
     */
    private function eraseTotpFactor(string $userId): array
    {
        $factor = $this->totpFactors->findByUserIdForUpdate($userId);

        if (!$factor instanceof TotpFactor) {
            return [0, null];
        }

        $scope = TotpSecretVault::scopeOf($factor->scopeId());
        $this->totpFactors->remove($factor);
        $this->keystore->destroy($scope, DestroyReason::Erasure);

        return [1, $scope->toString()];
    }
}
