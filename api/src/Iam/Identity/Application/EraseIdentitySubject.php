<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
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
 * The compliance record is a `GDPR_SUBJECT_ERASED` security audit entry committed in the same transaction.
 * It names the subject as its audit RESOURCE and keeps counts alone in its metadata, so the record survives
 * the erasure while the identifier in it does not: `resource_id` is rewritten to the anonymisation pseudonym
 * by the chain in {@see FulfilIdentityErasure}, which no key of `metadata` ever would be. Idempotent: a
 * re-run finds nothing live, erases nothing and skips the self-audit.
 */
final readonly class EraseIdentitySubject
{
    private const string ERASURE_ACTION = 'GDPR_SUBJECT_ERASED';

    public function __construct(
        private UserRepository $users,
        private PasswordResetTokenRepository $resetTokens,
        private AuditLogger $auditLogger,
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

            $result = new IdentityErasureResult($userId, $identityErased, $tokensDeleted);

            if ($result->erasedAnything()) {
                // The subject travels as the entry's RESOURCE, and the metadata carries counts alone. Both
                // halves matter: no anonymiser reaches inside `metadata`, so an id written there outlives the
                // erasure that wrote it, whereas `resource_id` is rewritten to the anonymisation pseudonym by
                // the resource anonymiser that {@see FulfilIdentityErasure} runs straight after this call, in
                // the same transaction. That is also why the pseudonym never has to be written here.
                $this->auditLogger->log(
                    self::ERASURE_ACTION,
                    AuditLevel::SECURITY,
                    AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId),
                    ['reset_tokens_deleted' => $tokensDeleted],
                );
            }

            return $result;
        });
    }
}
