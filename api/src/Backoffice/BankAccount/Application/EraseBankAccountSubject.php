<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * GDPR "right to erasure" for a bank-account subject — the counterpart to actor erasure, never merged with
 * it (ADR D15): actor erasure forgets *who acted*; this forgets *whose data appears in the diff*. It does
 * two things: removes the live record and destroys the scope's data-encryption key, so the PII ciphertext
 * in the append-only trail becomes permanently unreadable while the rows, their order and their integrity
 * remain — the trail is never touched, only its key is destroyed.
 *
 * A GDPR erasure is not a business deletion, so it does not go through the CLOSED-only lifecycle delete nor
 * emit a `BANK_ACCOUNT_DELETED` event; the compliance record is the `GDPR_SUBJECT_ERASED` security entry
 * plus the surviving (now-unreadable) change rows. The steps are not wrapped in one transaction — like the
 * sibling actor erasure (D4), each step is idempotent, so a partial failure is fully recovered by re-running:
 * a re-run removes nothing and destroys nothing, and skips the self-audit.
 */
final readonly class EraseBankAccountSubject
{
    private const string ERASURE_ACTION = 'GDPR_SUBJECT_ERASED';

    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private EnvelopeEncryptor $encryptor,
        private AuditLogger $auditLogger,
    ) {
    }

    public function execute(string $bankAccountId): SubjectErasureResult
    {
        Uuid::ensure($bankAccountId);
        $scope = EncryptionScopeId::forBankAccount($bankAccountId);

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
    }
}
