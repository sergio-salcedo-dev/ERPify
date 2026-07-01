<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Audit\Infrastructure\Persistence\PiiDiffSealer;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the PII crypto-shredding of a `BankAccount` write against a real Postgres: the
 * `onFlush` capture seals `holderName`/`iban` under the account's encryption scope, leaves `bic` in clear,
 * references the scope on the row, and mints the wrapped DEK in the same transaction — while a `Bank`
 * catalog write stays in clear with no scope.
 *
 * Runs inside a transaction that is always rolled back, so nothing escapes the shared dev database.
 *
 * @internal
 */
#[CoversClass(PiiDiffSealer::class)]
final class BankAccountAuditCryptoShreddingFunctionalTest extends KernelTestCase
{
    public function testABankAccountWriteSealsItsPersonalFieldsAndReferencesTheScope(): void
    {
        $this->inRolledBackTransaction(function (EntityManagerInterface $em, Connection $connection): void {
            $bankId = Uuid::generate();
            $token = \strtoupper(\substr(\str_replace('-', '', $bankId), 0, 8));
            $em->persist(Bank::create($bankId, 'Bank ' . $bankId, 'BNK' . $token));
            $em->flush(); // the bank must exist before the account's foreign key

            $accountId = Uuid::generate();
            $holderName = 'Juan Pérez';
            $iban = 'ES9121000418450200051332';
            $em->persist(BankAccount::create($accountId, $bankId, $holderName, $iban, 'CAIXESBBXXX'));
            $em->flush();

            $row = $this->changeRow($connection, $accountId, 'BANK_ACCOUNT_CREATED');
            $metadata = $row['metadata'] ?? null;
            $this->assertIsString($metadata);

            $this->assertSame('BankAccount:' . $accountId, $row['encryption_scope_id'] ?? null);
            $this->assertStringNotContainsString($holderName, $metadata, 'holderName is never stored in clear');
            $this->assertStringNotContainsString($iban, $metadata, 'iban is never stored in clear');
            $this->assertStringContainsString(PiiDiffSealer::ENCRYPTED_MARKER, $metadata, 'PII fields are sealed');
            $this->assertStringContainsString('CAIXESBBXXX', $metadata, 'bic is not PII and stays in clear');

            $this->assertSame(
                1,
                $this->keystoreRowsFor($connection, 'BankAccount:' . $accountId),
                'the wrapped DEK is minted in the same transaction as the write',
            );

            // Regression: a Bank catalog change stays in clear and references no scope.
            $bankRow = $this->changeRow($connection, $bankId, 'BANK_CREATED');
            $this->assertNull($bankRow['encryption_scope_id'] ?? null, 'a non-PII catalog references no scope');
        });
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

    /**
     * @return array<string, mixed>
     */
    private function changeRow(Connection $connection, string $resourceId, string $action): array
    {
        $row = $connection->fetchAssociative(
            "SELECT * FROM audit_log WHERE level = 'change' AND resource_id = :id AND action = :action",
            ['id' => $resourceId, 'action' => $action],
        );

        $this->assertIsArray($row, \sprintf('expected one %s change row for %s', $action, $resourceId));

        return $row;
    }

    private function keystoreRowsFor(Connection $connection, string $scope): int
    {
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM dek_keystore WHERE encryption_scope_id = :scope',
            ['scope' => $scope],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
