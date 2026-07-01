<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for GDPR subject erasure against a real Postgres: erasing a bank-account subject removes
 * the live record and destroys its encryption key, so the append-only audit rows survive but their PII is
 * unreadable; a `GDPR_SUBJECT_ERASED` security entry records it; a re-run is a no-op; and the actor-erasure
 * locus (`actor_erased`) is never touched (ADR D15 — distinct GDPR triggers).
 *
 * Runs inside a transaction that is always rolled back, so nothing escapes the shared dev database.
 *
 * @internal
 */
#[CoversClass(EraseBankAccountSubject::class)]
final class EraseBankAccountSubjectFunctionalTest extends KernelTestCase
{
    public function testErasingASubjectShredsItsAuditPiiWhileTheRowsSurvive(): void
    {
        $this->inRolledBackTransaction(function (EntityManagerInterface $em, Connection $connection): void {
            $bankId = Uuid::generate();
            $token = \strtoupper(\substr(\str_replace('-', '', $bankId), 0, 8));
            $em->persist(Bank::create($bankId, 'Bank ' . $bankId, 'BNK' . $token));
            $em->flush();

            $accountId = Uuid::generate();
            $em->persist(BankAccount::create($accountId, $bankId, 'Juan Pérez', 'ES9121000418450200051332'));
            $em->flush();

            $scope = 'BankAccount:' . $accountId;
            $changeRowsBefore = $this->countChangeRows($connection, $accountId);
            $this->assertGreaterThan(0, $changeRowsBefore, 'the create write was captured');
            $this->assertSame(1, $this->liveKeys($connection, $scope), 'a DEK was minted for the subject');

            $result = $this->eraser()->execute($accountId);

            $this->assertTrue($result->liveRecordErased);
            $this->assertTrue($result->keyDestroyed);
            $this->assertSame(0, $this->bankAccountRows($connection, $accountId), 'the live record is gone');
            $this->assertSame(
                $changeRowsBefore + 1,
                $this->countChangeRows($connection, $accountId),
                'append-only: the surviving rows grow by the captured BANK_ACCOUNT_DELETED snapshot',
            );
            $this->assertSame(0, $this->liveKeys($connection, $scope), 'the DEK is destroyed');
            $this->assertSame(1, $this->subjectErasedRows($connection, $scope), 'the erasure self-audits once');
            $this->assertFalse(
                $this->hasEvidencelessDestroyedKey($connection, $scope),
                'reconciliation: the destroyed key commits with its GDPR_SUBJECT_ERASED evidence row',
            );
            $this->assertFalse(
                $this->createRowActorErased($connection, $accountId),
                'subject erasure never touches the actor-erasure locus',
            );

            $rerun = $this->eraser()->execute($accountId);

            $this->assertFalse($rerun->liveRecordErased, 'idempotent: nothing left to remove');
            $this->assertFalse($rerun->keyDestroyed, 'idempotent: the key is already gone');
            $this->assertSame(1, $this->subjectErasedRows($connection, $scope), 'a no-op re-run does not self-audit');
        });
    }

    public function testAReconciliationCrossCheckDetectsADestroyedKeyMissingItsEvidence(): void
    {
        $this->inRolledBackTransaction(function (EntityManagerInterface $em, Connection $connection): void {
            $bankId = Uuid::generate();
            $token = \strtoupper(\substr(\str_replace('-', '', $bankId), 0, 8));
            $em->persist(Bank::create($bankId, 'Bank ' . $bankId, 'BNK' . $token));
            $em->flush();

            $accountId = Uuid::generate();
            $em->persist(BankAccount::create($accountId, $bankId, 'Juan Pérez', 'ES9121000418450200051332'));
            $em->flush();

            $scope = 'BankAccount:' . $accountId;
            $this->assertSame(1, $this->liveKeys($connection, $scope), 'a DEK was minted for the subject');

            // Destroy the key WITHOUT the self-audit — the exact divergence a compliance reconciliation exists
            // to catch (the atomic erase use case can never produce it; a manual repair or a future bug could).
            $this->encryptor()->destroyScope(EncryptionScopeId::forBankAccount($accountId));

            $this->assertSame(0, $this->liveKeys($connection, $scope), 'the DEK is destroyed');
            $this->assertSame(0, $this->subjectErasedRows($connection, $scope), 'no evidence row was written');
            $this->assertTrue(
                $this->hasEvidencelessDestroyedKey($connection, $scope),
                'reconciliation detects a destroyed key with no GDPR_SUBJECT_ERASED evidence',
            );
        });
    }

    private function eraser(): EraseBankAccountSubject
    {
        $eraser = self::getContainer()->get(EraseBankAccountSubject::class);
        $this->assertInstanceOf(EraseBankAccountSubject::class, $eraser);

        return $eraser;
    }

    private function encryptor(): EnvelopeEncryptor
    {
        $encryptor = self::getContainer()->get(EnvelopeEncryptor::class);
        $this->assertInstanceOf(EnvelopeEncryptor::class, $encryptor);

        return $encryptor;
    }

    /**
     * @param callable(EntityManagerInterface, Connection): void $work
     */
    private function inRolledBackTransaction(callable $work): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $work($entityManager, $connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function countChangeRows(Connection $connection, string $resourceId): int
    {
        return $this->countRows(
            $connection,
            "SELECT COUNT(*) FROM audit_log WHERE level = 'change' AND resource_id = :id",
            ['id' => $resourceId],
        );
    }

    private function bankAccountRows(Connection $connection, string $id): int
    {
        return $this->countRows($connection, 'SELECT COUNT(*) FROM bank_account WHERE id = :id', ['id' => $id]);
    }

    private function liveKeys(Connection $connection, string $scope): int
    {
        return $this->countRows(
            $connection,
            'SELECT COUNT(*) FROM dek_keystore WHERE encryption_scope_id = :scope AND destroyed_at IS NULL',
            ['scope' => $scope],
        );
    }

    private function subjectErasedRows(Connection $connection, string $scope): int
    {
        return $this->countRows(
            $connection,
            "SELECT COUNT(*) FROM audit_log WHERE level = 'security' AND action = 'GDPR_SUBJECT_ERASED' "
            . "AND metadata->>'encryption_scope_id' = :scope",
            ['scope' => $scope],
        );
    }

    private function createRowActorErased(Connection $connection, string $accountId): bool
    {
        $value = $connection->fetchOne(
            "SELECT actor_erased FROM audit_log WHERE resource_id = :id AND action = 'BANK_ACCOUNT_CREATED'",
            ['id' => $accountId],
        );

        return \in_array($value, [true, 't', '1', 1], true);
    }

    private function hasEvidencelessDestroyedKey(Connection $connection, string $scope): bool
    {
        $divergent = $this->countRows(
            $connection,
            'SELECT COUNT(*) FROM dek_keystore k WHERE k.encryption_scope_id = :scope AND k.destroyed_at IS NOT NULL '
            . "AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.level = 'security' "
            . "AND a.action = 'GDPR_SUBJECT_ERASED' AND a.metadata->>'encryption_scope_id' = k.encryption_scope_id)",
            ['scope' => $scope],
        );

        return $divergent > 0;
    }

    /**
     * @param array<string, string> $params
     */
    private function countRows(Connection $connection, string $sql, array $params): int
    {
        $count = $connection->fetchOne($sql, $params);
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
