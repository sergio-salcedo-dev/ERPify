<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
use Erpify\Backoffice\BankAccount\Application\Evidence\ReleaseBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountHasLiveEvidence;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\DoctrineBankAccountEvidenceRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * A bank-account subject's erasure over an account that holds evidence, against real Postgres: a live document refuses
 * the whole erasure before anything irreversible (the record and its key survive, no evidence row is written); with
 * every document erased the links go before the account, each with its deletion row, and the key is destroyed. Also
 * the concurrent attach, met at the named unique constraint on the document.
 *
 * Runs inside a transaction that is always rolled back.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the erasure, the link repository and the rows they touch
 */
#[CoversClass(EraseBankAccountSubject::class)]
#[CoversClass(ReleaseBankAccountEvidence::class)]
#[CoversClass(DoctrineBankAccountEvidenceRepository::class)]
final class BankAccountEvidenceSubjectErasureFunctionalTest extends KernelTestCase
{
    #[Test]
    public function aLiveLinkedDocumentRefusesTheErasureAndLeavesTheRecordTheKeyAndTheTrailAsTheyWere(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $accountId = $this->plantAccount($connection);
            $scope = 'BankAccount:' . $accountId;
            $this->link($connection, $accountId, $this->plantDocument($connection, 'ready'));
            $keysBefore = $this->countRows(
                $connection,
                'SELECT COUNT(*) FROM dek_keystore WHERE encryption_scope_id = :s AND destroyed_at IS NULL',
                ['s' => $scope],
            );

            $thrown = null;

            try {
                $this->service(EraseBankAccountSubject::class)->execute($accountId);
            } catch (Throwable $throwable) {
                $thrown = $throwable;
            }

            $this->assertInstanceOf(BankAccountHasLiveEvidence::class, $thrown);
            $this->assertSame(1, $this->countRows($connection, 'SELECT COUNT(*) FROM bank_account WHERE id = :id', [
                'id' => $accountId,
            ]));
            $this->assertSame(1, $this->countRows($connection, 'SELECT COUNT(*) FROM bank_account_evidence', []));
            $this->assertSame($keysBefore, $this->countRows(
                $connection,
                'SELECT COUNT(*) FROM dek_keystore WHERE encryption_scope_id = :s AND destroyed_at IS NULL',
                ['s' => $scope],
            ));
            $this->assertSame(0, $this->countRows(
                $connection,
                "SELECT COUNT(*) FROM audit_log WHERE action = 'GDPR_SUBJECT_ERASED' "
                . "AND metadata->>'encryption_scope_id' = :s",
                ['s' => $scope],
            ));
        });
    }

    #[Test]
    public function withEveryLinkedDocumentErasedTheLinksGoWithTheAccountAndTheKeyIsDestroyed(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $accountId = $this->plantAccount($connection);
            $scope = 'BankAccount:' . $accountId;
            $this->link($connection, $accountId, $this->plantDocument($connection, 'erased'));
            $this->link($connection, $accountId, $this->plantDocument($connection, 'erased'));

            $result = $this->service(EraseBankAccountSubject::class)->execute($accountId);

            $this->assertTrue($result->liveRecordErased);
            $this->assertSame(0, $this->countRows($connection, 'SELECT COUNT(*) FROM bank_account_evidence', []));
            $this->assertSame(0, $this->countRows($connection, 'SELECT COUNT(*) FROM bank_account WHERE id = :id', [
                'id' => $accountId,
            ]));
            $this->assertSame(2, $this->countRows(
                $connection,
                "SELECT COUNT(*) FROM audit_log WHERE action = 'BANK_ACCOUNT_EVIDENCE_DELETED' "
                . 'AND encryption_scope_id = :s',
                ['s' => $scope],
            ), 'one deletion row per link, its document id sealed under the account scope');
            $this->assertSame(0, $this->countRows(
                $connection,
                'SELECT COUNT(*) FROM dek_keystore WHERE encryption_scope_id = :s AND destroyed_at IS NULL',
                ['s' => $scope],
            ), 'the account key — which seals the links\' document ids too — is destroyed');
        });
    }

    #[Test]
    public function aConcurrentAttachOfAHeldDocumentMeetsTheNamedConstraint(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $accountId = $this->plantAccount($connection);
            $documentId = $this->plantDocument($connection, 'ready');
            // The competitor committed first, behind the attach's own pre-check.
            $this->link($connection, $accountId, $documentId);

            $thrown = null;

            try {
                $this->service(BankAccountEvidenceRepository::class)->add(BankAccountEvidence::attach(
                    Uuid::generate(),
                    $accountId,
                    $documentId,
                    BankAccountEvidenceKind::OwnershipCertificate,
                ));
            } catch (Throwable $throwable) {
                $thrown = $throwable;
            }

            $this->assertInstanceOf(BankAccountEvidenceAlreadyAttached::class, $thrown);
            $this->assertNotInstanceOf(Throwable::class, $thrown->getPrevious(), 'the driver text names the key');
        });
    }

    /**
     * @param callable(Connection): void $work
     */
    private function inRolledBackTransaction(callable $work): void
    {
        self::bootKernel();
        $connection = $this->service(EntityManagerInterface::class)->getConnection();
        $connection->beginTransaction();

        try {
            $work($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function plantAccount(Connection $connection): string
    {
        $bankId = Uuid::generate();
        $accountId = Uuid::generate();
        $connection->executeStatement(
            'INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) '
            . 'VALUES (:id, :name, :name, :short, NOW(), NOW())',
            ['id' => $bankId, 'name' => 'bank ' . $bankId, 'short' => 'B' . \substr($bankId, 0, 6)],
        );
        $connection->executeStatement(
            'INSERT INTO bank_account (id, bank_id, holder_name, iban, currency, status, created_at, updated_at) '
            . "VALUES (:id, :bank, 'Evidence Holder', 'GB33BUKB20201555555555', 'EUR', 'ACTIVE', NOW(), NOW())",
            ['id' => $accountId, 'bank' => $bankId],
        );

        return $accountId;
    }

    private function plantDocument(Connection $connection, string $state): string
    {
        $id = Uuid::generate();
        $connection->executeStatement(
            'INSERT INTO document_series (id, created_at) VALUES (:id, NOW())',
            ['id' => $id],
        );
        $connection->executeStatement(
            'INSERT INTO document (id, scope_id, series_id, uploader_id, state, digest, size_bytes, '
            . 'retention_reason, legal_basis, next_scan_at, created_at, updated_at) '
            . "VALUES (:id, :scope, :id, :uploader, :state, :digest, 10, 'contract-execution', "
            . "'legitimate-interests', NOW(), NOW(), NOW())",
            [
                'id' => $id,
                'scope' => Uuid::generate(),
                'uploader' => Uuid::generate(),
                'state' => $state,
                'digest' => \hash('sha256', $id),
            ],
        );

        return $id;
    }

    private function link(Connection $connection, string $accountId, string $documentId): void
    {
        $connection->executeStatement(
            'INSERT INTO bank_account_evidence (id, bank_account_id, document_id, kind, attached_at) '
            . "VALUES (:id, :account, :document, 'ownership-certificate', NOW())",
            ['id' => Uuid::generate(), 'account' => $accountId, 'document' => $documentId],
        );
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

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        $this->assertInstanceOf($id, $service);

        return $service;
    }
}
