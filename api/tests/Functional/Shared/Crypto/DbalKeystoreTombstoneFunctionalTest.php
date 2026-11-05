<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\Exception\KeystoreLockRequiresTransaction;
use Erpify\Shared\Crypto\Domain\KeyState;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The keystore's tombstones against a real Postgres: destroying a scope that was never minted leaves a
 * tombstone that refuses a late mint, the first tombstone keeps its reason and its time, the row lock reports
 * live, tombstoned and absent,.
 *
 * Runs inside a transaction that is always rolled back, so writes never escape the test.
 *
 * @internal
 */
#[CoversClass(DbalKeystore::class)]
final class DbalKeystoreTombstoneFunctionalTest extends KernelTestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    public function testDestroyingAScopeNeverMintedLeavesATombstoneThatRefusesALateMint(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $keystore = new DbalKeystore($connection);
            $scope = EncryptionScopeId::forDocument(Uuid::generate());

            $this->assertSame(KeyState::Absent, $keystore->lockState($scope));
            $this->assertTrue($keystore->destroy($scope, DestroyReason::AbortedUpload), 'the tombstone is written');
            $this->assertSame(KeyState::Tombstoned, $keystore->lockState($scope));
            $this->assertNull($keystore->wrappedDekFor($scope));

            $row = $this->rowOf($connection, $scope);
            $this->assertNull($row['wrapped_dek']);
            $this->assertSame('aborted-upload', $row['destroy_reason']);
            $this->assertSame(0, $row['kek_version'], 'a scope that never had a key carries the no-KEK sentinel');

            $keystore->store($scope, new WrappedDek('late-writer', 1));
            $this->assertNull($keystore->wrappedDekFor($scope), 'a late writer cannot mint under a tombstone');

            $encryptor = new SodiumEnvelopeEncryptor(new ScopeKeyring($keystore, self::KEK));
            $this->expectException(DekDestroyed::class);

            $encryptor->encrypt($scope, 'late', 'aad');
        });
    }

    public function testASecondDestroyNeverRelabelsOrRestampsAnExistingTombstone(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $keystore = new DbalKeystore($connection);
            $scope = EncryptionScopeId::forDocument(Uuid::generate());

            $keystore->destroy($scope, DestroyReason::Erasure);
            $connection->executeStatement(
                "UPDATE dek_keystore SET destroyed_at = TIMESTAMPTZ '2001-02-03 04:05:06+00' "
                . 'WHERE encryption_scope_id = :scope',
                ['scope' => $scope->toString()],
            );

            $this->assertFalse($keystore->destroy($scope, DestroyReason::AbortedUpload), 'first writer wins');

            $row = $this->rowOf($connection, $scope);
            $this->assertSame('erasure', $row['destroy_reason'], 'the reason is not relabelled');
            $this->assertStringStartsWith('2001-02-03 04:05:06', (string) $row['destroyed_at'], 'not re-stamped');
        });
    }

    public function testItReportsLiveTombstonedAndAbsentUnderALock(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $keystore = new DbalKeystore($connection);
            $live = EncryptionScopeId::forDocument(Uuid::generate());
            $tombstoned = EncryptionScopeId::forDocument(Uuid::generate());

            $keystore->store($live, new WrappedDek('bytes', 1));
            $keystore->destroy($tombstoned, DestroyReason::Erasure);

            $this->assertSame(KeyState::Live, $keystore->lockState($live));
            $this->assertSame(KeyState::Tombstoned, $keystore->lockState($tombstoned));
            $this->assertSame(KeyState::Absent, $keystore->lockState(EncryptionScopeId::forDocument(Uuid::generate())));
        });
    }

    public function testTheRowLockRequiresATransaction(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $this->assertFalse($connection->isTransactionActive());

        $this->expectException(KeystoreLockRequiresTransaction::class);

        (new DbalKeystore($connection))->lockState(EncryptionScopeId::forDocument(Uuid::generate()));
    }

    /**
     * @return array{wrapped_dek: ?string, kek_version: int, destroyed_at: ?string, destroy_reason: ?string}
     */
    private function rowOf(Connection $connection, EncryptionScopeId $scope): array
    {
        $row = $connection->fetchAssociative(
            'SELECT wrapped_dek, kek_version, destroyed_at, destroy_reason FROM dek_keystore '
            . 'WHERE encryption_scope_id = :scope',
            ['scope' => $scope->toString()],
        );
        $this->assertIsArray($row);

        return [
            'wrapped_dek' => \is_string($row['wrapped_dek'] ?? null) ? $row['wrapped_dek'] : null,
            'kek_version' => \is_numeric($row['kek_version'] ?? null) ? (int) $row['kek_version'] : -1,
            'destroyed_at' => \is_string($row['destroyed_at'] ?? null) ? $row['destroyed_at'] : null,
            'destroy_reason' => \is_string($row['destroy_reason'] ?? null) ? $row['destroy_reason'] : null,
        ];
    }

    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $body($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
