<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Audit\Application\AuditPiiAad;
use Erpify\Shared\Audit\Application\PiiFieldPosition;
use Erpify\Shared\Audit\Infrastructure\Persistence\PiiDiffSealer;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the PII crypto-shredding of a `BankAccount` write against a real Postgres: the
 * `onFlush` capture seals `holderName`/`iban` under the account's encryption scope, leaves `bic` in clear,
 * references the scope on the row, and mints the wrapped DEK in the same transaction — while a `Bank`
 * catalog write stays in clear with no scope. Each sealed value opens only under the exact
 * `field|old/new|row-id` AAD it was sealed with, so a ciphertext relocated within the subject fails.
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

            // The row id is bound into each sealed value's AAD: the holderName ciphertext decrypts only
            // under the id of the very row it landed on, and relocating it to another field fails to open.
            $encryptor = $this->encryptor();
            $auditLogId = $row['id'] ?? null;
            $this->assertIsString($auditLogId);
            $scope = EncryptionScopeId::forBankAccount($accountId);
            $decoded = \json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $holderCiphertext = $this->sealedCiphertext($decoded, 'holderName');

            $genuineAad = AuditPiiAad::for('holderName', PiiFieldPosition::New, $auditLogId)->toString();
            $this->assertSame(
                $holderName,
                $encryptor->decrypt($scope, $holderCiphertext, $genuineAad),
                'the sealed holderName decrypts under the row id it was actually stored on',
            );

            $relocatedAad = AuditPiiAad::for('iban', PiiFieldPosition::New, $auditLogId)->toString();

            try {
                $encryptor->decrypt($scope, $holderCiphertext, $relocatedAad);
                $this->fail('a ciphertext moved to another field must not authenticate');
            } catch (DecryptionFailed) {
                // expected: the field name is bound into the AAD
            }

            // Regression: a Bank catalog change stays in clear and references no scope.
            $bankRow = $this->changeRow($connection, $bankId, 'BANK_CREATED');
            $this->assertNull($bankRow['encryption_scope_id'] ?? null, 'a non-PII catalog references no scope');
        });
    }

    /**
     * Several PII-bearing entities in ONE flush, which is what puts several keystore mints inside a single
     * `onFlush`: `AuditWriteCaptureListener` loops the scheduled entities and each iteration reaches
     * `SodiumEnvelopeEncryptor::mint()`, an `INSERT` plus a follow-up `SELECT` issued on the flush
     * connection while the UnitOfWork is still being iterated. Running side queries there is the hazard.
     *
     * The load-bearing assertion is the **cross-open**, not the count: every account is sealed under its
     * own scope AND its own row id, so a ciphertext from one account must fail to authenticate under the
     * sibling minted microseconds earlier in the same flush. A per-account count would stay green if the
     * listener reused one scope, or one AAD, for every entity in the batch.
     */
    public function testSeveralPersonalEntitiesInOneFlushEachGetTheirOwnScopeAndAad(): void
    {
        $this->inRolledBackTransaction(function (EntityManagerInterface $em, Connection $connection): void {
            $bankId = Uuid::generate();
            $token = \strtoupper(\substr(\str_replace('-', '', $bankId), 0, 8));
            $em->persist(Bank::create($bankId, 'Bank ' . $bankId, 'BNK' . $token));
            $em->flush(); // the bank must exist before either account's foreign key

            $firstId = Uuid::generate();
            $secondId = Uuid::generate();
            $firstHolder = 'Ana Ruiz';
            $secondHolder = 'Bruno Salas';
            $iban = 'ES9121000418450200051332';
            $em->persist(BankAccount::create($firstId, $bankId, $firstHolder, $iban, 'CAIXESBBXXX'));
            $em->persist(
                BankAccount::create($secondId, $bankId, $secondHolder, 'DE89370400440532013000', 'DEUTDEFFXXX'),
            );
            $em->flush(); // one flush, two mints

            $encryptor = $this->encryptor();
            $first = $this->sealedHolderOf($connection, $firstId, $firstHolder);
            $second = $this->sealedHolderOf($connection, $secondId, $secondHolder);

            $sealedPairs = [[$firstId, $firstHolder, $first], [$secondId, $secondHolder, $second]];

            foreach ($sealedPairs as [$id, $holder, $sealed]) {
                $aad = AuditPiiAad::for('holderName', PiiFieldPosition::New, $sealed['auditLogId'])->toString();
                $this->assertSame(
                    $holder,
                    $encryptor->decrypt(EncryptionScopeId::forBankAccount($id), $sealed['ciphertext'], $aad),
                    'each account opens under its own scope and its own row id',
                );
            }

            // The cross-open: the first account's ciphertext under the second account's scope and row id.
            $crossAad = AuditPiiAad::for('holderName', PiiFieldPosition::New, $second['auditLogId'])->toString();

            try {
                $encryptor->decrypt(
                    EncryptionScopeId::forBankAccount($secondId),
                    $first['ciphertext'],
                    $crossAad,
                );
                $this->fail("a sibling minted in the same flush must not open the other account's ciphertext");
            } catch (DecryptionFailed) {
                // expected: scope and row id are both bound, per entity, not per flush
            }
        });
    }

    /**
     * The per-account checks that must hold whatever else shared the flush, plus the sealed holder the
     * cross-open needs afterwards.
     *
     * @return array{ciphertext: string, auditLogId: string}
     */
    private function sealedHolderOf(Connection $connection, string $accountId, string $holder): array
    {
        $row = $this->changeRow($connection, $accountId, 'BANK_ACCOUNT_CREATED');
        $metadata = $row['metadata'] ?? null;
        $auditLogId = $row['id'] ?? null;
        $this->assertIsString($metadata);
        $this->assertIsString($auditLogId);

        $this->assertSame('BankAccount:' . $accountId, $row['encryption_scope_id'] ?? null);
        $this->assertStringNotContainsString($holder, $metadata, 'no holder survives in clear');
        $this->assertSame(
            1,
            $this->keystoreRowsFor($connection, 'BankAccount:' . $accountId),
            'each account mints exactly one wrapped DEK, even sharing a flush',
        );

        $decoded = \json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return ['ciphertext' => $this->sealedCiphertext($decoded, 'holderName'), 'auditLogId' => $auditLogId];
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

    private function encryptor(): EnvelopeEncryptor
    {
        $encryptor = self::getContainer()->get(EnvelopeEncryptor::class);
        $this->assertInstanceOf(EnvelopeEncryptor::class, $encryptor);

        return $encryptor;
    }

    /**
     * @param array<mixed, mixed> $metadata
     */
    private function sealedCiphertext(array $metadata, string $field): string
    {
        $changes = $metadata['changes'] ?? null;
        $this->assertIsArray($changes);
        $slot = $changes[$field] ?? null;
        $this->assertIsArray($slot);
        $sealed = $slot['new'] ?? null;
        $this->assertIsArray($sealed);
        $ciphertext = $sealed[PiiDiffSealer::ENCRYPTED_MARKER] ?? null;
        $this->assertIsString($ciphertext);

        return $ciphertext;
    }
}
