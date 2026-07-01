<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the keystore against a real Postgres: a wrapped key stores, fetches, is protected by
 * `ON CONFLICT DO NOTHING`, and destroys idempotently — the destroyed row no longer yields a key.
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
            $this->assertTrue($keystore->destroy($scope));
            $this->assertNull($keystore->wrappedDekFor($scope), 'the key is gone after destruction');
            $this->assertFalse($keystore->destroy($scope));
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
