<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * GDPR "right to erasure" for an identity subject — the identity-context counterpart to
 * {@see \Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject} (kept per-module: each context
 * owns forgetting its own subject data). It hard-deletes the identity aggregate (the module's PII is the
 * user row itself — email and credential hash) AND every pending password-reset token, in one transaction,
 * so no `user_id` linkage or credential-recovery artefact outlives the subject.
 *
 * The compliance record is a `GDPR_SUBJECT_ERASED` security audit entry committed in the same transaction —
 * its metadata carries only the pseudonymous subject id, never the erased email. Idempotent: a re-run finds
 * nothing live, erases nothing and skips the self-audit.
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
                $this->auditLogger->log(self::ERASURE_ACTION, AuditLevel::SECURITY, null, [
                    'subject_user_id' => $userId,
                    'reset_tokens_deleted' => $tokensDeleted,
                ]);
            }

            return $result;
        });
    }
}
