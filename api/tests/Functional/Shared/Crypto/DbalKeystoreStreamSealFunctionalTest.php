<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumStreamEnvelopeEncryptor;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The stream seal over the real keystore: a scope tombstoned before it was ever minted refuses the late
 * writer, leaves the sink empty and mints no key behind the tombstone. The same fidelity the string encryptor
 * has, proved on the Postgres row rather than on the in-memory double.
 *
 * Runs inside a transaction that is always rolled back, so writes never escape the test.
 *
 * @internal
 */
#[CoversClass(DbalKeystore::class)]
#[CoversClass(SodiumStreamEnvelopeEncryptor::class)]
final class DbalKeystoreStreamSealFunctionalTest extends KernelTestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    public function testTheStreamSealRefusesAScopeDestroyedBeforeItWasMintedAndWritesNothing(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $keystore = new DbalKeystore($connection);
            $encryptor = new SodiumStreamEnvelopeEncryptor(new ScopeKeyring($keystore, self::KEK));
            $scope = EncryptionScopeId::forDocument(Uuid::generate());
            $keystore->destroy($scope, DestroyReason::AbortedUpload);

            $source = \fopen('php://memory', 'r+');
            $sealed = \fopen('php://memory', 'r+');
            $this->assertIsResource($source);
            $this->assertIsResource($sealed);
            \fwrite($source, 'late writer');
            \rewind($source);

            try {
                $encryptor->seal($scope, $source, $sealed, 'original|v1');
                $this->fail('A late writer must not seal under a tombstoned scope.');
            } catch (DekDestroyed) {
                \rewind($sealed);
                $this->assertSame('', \stream_get_contents($sealed), 'nothing was written to the sink');
                $this->assertNull($keystore->wrappedDekFor($scope), 'and no key was minted behind the tombstone');
            }

            $this->expectException(DekDestroyed::class);

            $encryptor->unseal($scope, $source, $sealed, 'original|v1');
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
