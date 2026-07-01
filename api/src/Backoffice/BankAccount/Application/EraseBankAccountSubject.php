<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * GDPR "right to erasure" for a bank-account subject — the counterpart to actor erasure, never merged with
 * it (ADR D15): actor erasure forgets *who acted*; this forgets *whose data appears in the diff*. It does
 * two things: removes the live record and destroys the scope's data-encryption key, so the PII ciphertext
 * in the append-only trail becomes permanently unreadable while the rows, their order and their integrity
 * remain — the trail is never touched, only its key is destroyed.
 *
 * The two effects run in one transaction so they commit or roll back together (ADR D15 atomicity): removing
 * the live record captures a final `BANK_ACCOUNT_DELETED` change row — its PII sealed under the still-live
 * key — before the same transaction destroys that key, and the `GDPR_SUBJECT_ERASED` security entry commits
 * with them, so a destroyed key always has its evidence row. It is not a business deletion (no domain event,
 * no CLOSED-only lifecycle guard); the compliance record is that security entry plus the surviving,
 * now-unreadable change rows. Idempotent: a re-run finds nothing live and an already-destroyed key, erases
 * nothing and skips the self-audit.
 */
final readonly class EraseBankAccountSubject
{
    private const string ERASURE_ACTION = 'GDPR_SUBJECT_ERASED';

    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private EnvelopeEncryptor $encryptor,
        private AuditLogger $auditLogger,
        private TransactionManager $transactionManager,
    ) {
    }

    public function execute(string $bankAccountId): SubjectErasureResult
    {
        Uuid::ensure($bankAccountId);
        $scope = EncryptionScopeId::forBankAccount($bankAccountId);

        return $this->transactionManager->transactional(function () use ($bankAccountId, $scope): SubjectErasureResult {
            $account = $this->bankAccountRepository->findById($bankAccountId);
            $liveRecordErased = $account instanceof BankAccount;

            if ($liveRecordErased) {
                $this->bankAccountRepository->remove($account);
            }

            $keyDestroyed = $this->encryptor->destroyScope($scope);

            $result = new SubjectErasureResult($scope->toString(), $liveRecordErased, $keyDestroyed);

            if ($result->erasedAnything()) {
                $this->auditLogger->log(self::ERASURE_ACTION, AuditLevel::SECURITY, null, [
                    'encryption_scope_id' => $result->encryptionScopeId,
                ]);
            }

            return $result;
        });
    }
}
