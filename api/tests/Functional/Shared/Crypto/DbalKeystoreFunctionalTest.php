<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the keystore against a real Postgres: a wrapped key stores, fetches, is protected by
 * `ON CONFLICT DO NOTHING`, and destroys idempotently — the destroyed row no longer yields a key; the schema
 * refuses a tombstone without a reason.
 *
 * Runs inside a transaction that is always rolled back, so writes never escape the test.
 *
 * @internal
 */
#[CoversClass(DbalKeystore::class)]
#[CoversClass(WrappedDek::class)]
final class DbalKeystoreFunctionalTest extends KernelTestCase
{
    public function testItStoresFetchesAndDestroysAWrappedKey(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $keystore = new DbalKeystore($connection);
            $scope = EncryptionScopeId::forBankAccount(Uuid::generate());

            $this->assertNotInstanceOf(WrappedDek::class, $keystore->wrappedDekFor($scope), 'no key yet');

            $keystore->store($scope, new WrappedDek('raw-dek-bytes', 1));

            $fetched = $keystore->wrappedDekFor($scope);
            $this->assertInstanceOf(WrappedDek::class, $fetched);
            $this->assertSame('raw-dek-bytes', $fetched->bytes, 'the wrapped bytes round-trip through storage');
            $this->assertSame(1, $fetched->kekVersion);

            // ON CONFLICT DO NOTHING: a second store never overwrites the first key.
            $keystore->store($scope, new WrappedDek('other-bytes', 2));
            $this->assertSame('raw-dek-bytes', $keystore->wrappedDekFor($scope)?->bytes);

            // Destroy makes the key unreadable; the second destroy is a no-op (idempotent).
            $this->assertTrue($keystore->destroy($scope, DestroyReason::Erasure));
            $this->assertNull($keystore->wrappedDekFor($scope), 'the key is gone after destruction');
            $this->assertFalse($keystore->destroy($scope, DestroyReason::Erasure));

            $this->assertSame(
                'erasure',
                $connection->fetchOne(
                    'SELECT destroy_reason FROM dek_keystore WHERE encryption_scope_id = :scope',
                    ['scope' => $scope->toString()],
                ),
            );
        });
    }

    public function testATombstoneWithoutAReasonIsRefusedByTheSchema(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $scope = EncryptionScopeId::forDocument(Uuid::generate())->toString();

            foreach ([null, 'unknown'] as $reason) {
                $connection->executeStatement('SAVEPOINT before_insert');

                try {
                    $connection->executeStatement(
                        'INSERT INTO dek_keystore '
                        . '(encryption_scope_id, wrapped_dek, kek_version, created_at, destroyed_at, destroy_reason) '
                        . 'VALUES (:scope, NULL, 0, NOW(), NOW(), :reason)',
                        ['scope' => $scope, 'reason' => $reason],
                    );
                    $this->fail(\sprintf('A tombstone with reason %s must be refused.', \var_export($reason, true)));
                } catch (DriverException $refused) {
                    $this->assertSame('23514', $refused->getSQLState(), 'a check violation');
                    $connection->executeStatement('ROLLBACK TO SAVEPOINT before_insert');
                }
            }

            try {
                $connection->executeStatement(
                    'INSERT INTO dek_keystore '
                    . '(encryption_scope_id, wrapped_dek, kek_version, created_at, destroy_reason) '
                    . "VALUES (:scope, 'key', 1, NOW(), 'erasure')",
                    ['scope' => $scope],
                );
                $this->fail('A live key must not carry a destroy reason.');
            } catch (DriverException $driverException) {
                $this->assertSame('23514', $driverException->getSQLState());
            }
        });
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
