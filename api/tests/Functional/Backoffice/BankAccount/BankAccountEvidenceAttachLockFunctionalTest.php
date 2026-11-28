<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Application\Command\AttachBankAccountEvidenceCommand;
use Erpify\Backoffice\BankAccount\Application\Evidence\AttachBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\EvidenceKind;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The attach and the account's removal are serialised on the account's row: the attach takes it `FOR UPDATE` before
 * it looks at the document, so a link can never land between the removal's read of the evidence and its `DELETE`.
 * Proved against a real Postgres: a second connection holds the row, and the attach, under a short `lock_timeout`,
 * is refused the lock (SQLSTATE 55P03) instead of writing.
 *
 * The account is committed from the second connection and removed at the end, because a lock held by another session
 * is only visible on a committed row.
 *
 * @internal
 */
#[CoversClass(AttachBankAccountEvidence::class)]
final class BankAccountEvidenceAttachLockFunctionalTest extends KernelTestCase
{
    private const string LOCK_NOT_AVAILABLE = '55P03';

    private ?Connection $outside = null;

    private string $bankId;

    private string $accountId;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->bankId = Uuid::generate();
        $this->accountId = Uuid::generate();
    }

    protected function tearDown(): void
    {
        $outside = $this->outsideConnection();

        if ($outside->isTransactionActive()) {
            $outside->rollBack();
        }

        $outside->executeStatement('DELETE FROM bank_account WHERE id = :id', ['id' => $this->accountId]);
        $outside->executeStatement('DELETE FROM bank WHERE id = :id', ['id' => $this->bankId]);
        $outside->close();
        parent::tearDown();
    }

    #[Test]
    public function anAttachWaitsForTheAccountRowAnotherTransactionHolds(): void
    {
        $outside = $this->outsideConnection();
        $token = \strtoupper(\substr(\str_replace('-', '', $this->bankId), 0, 8));
        $outside->executeStatement(
            'INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) '
            . 'VALUES (:id, :name, :normalized, :short, NOW(), NOW())',
            [
                'id' => $this->bankId,
                'name' => 'Bank ' . $token,
                'normalized' => 'bank ' . \strtolower($token),
                'short' => 'BNK' . $token,
            ],
        );
        $outside->executeStatement(
            'INSERT INTO bank_account (id, bank_id, holder_name, iban, currency, status, created_at, updated_at) '
            . "VALUES (:id, :bank, 'Lock Holder', :iban, 'EUR', 'ACTIVE', NOW(), NOW())",
            ['id' => $this->accountId, 'bank' => $this->bankId, 'iban' => 'XX' . \substr($token, 0, 8)],
        );

        $outside->beginTransaction();
        $outside->executeStatement('SELECT id FROM bank_account WHERE id = :id FOR UPDATE', ['id' => $this->accountId]);

        $connection = $this->service(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("SET lock_timeout = '200ms'");

        $refusal = null;

        try {
            $this->service(AttachBankAccountEvidence::class)->attach(
                $this->accountId,
                new AttachBankAccountEvidenceCommand(Uuid::generate(), EvidenceKind::OwnershipCertificate),
            );
        } catch (Throwable $throwable) {
            $refusal = $throwable;
        } finally {
            $connection->executeStatement('RESET lock_timeout');
        }

        $this->assertInstanceOf(
            Throwable::class,
            $refusal,
            'The attach wrote while another transaction held the account row.',
        );
        $this->assertTrue(
            $this->carriesSqlState($refusal, self::LOCK_NOT_AVAILABLE),
            \sprintf('The attach failed for another reason: %s', $refusal::class),
        );
        $this->assertSame(
            0,
            $outside->executeQuery(
                'SELECT id FROM bank_account_evidence WHERE bank_account_id = :id',
                ['id' => $this->accountId],
            )->rowCount(),
        );
    }

    private function carriesSqlState(Throwable $throwable, string $sqlState): bool
    {
        for ($cause = $throwable; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if (\str_contains($cause->getMessage(), $sqlState)) {
                return true;
            }
        }

        return false;
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

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $params = $this->service(EntityManagerInterface::class)->getConnection()->getParams();
            $this->outside = DriverManager::getConnection($params);
        }

        return $this->outside;
    }
}
