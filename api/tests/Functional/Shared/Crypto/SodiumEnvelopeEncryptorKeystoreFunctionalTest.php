<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The envelope over the real keystore end-to-end: a DEK is minted on first encrypt and persisted, a fresh
 * encryptor reading the persisted DEK decrypts, and destroying the scope crypto-shreds the value — it is
 * permanently unreadable while the (now key-less) row survives.
 *
 * Runs inside a rolled-back transaction, so the keystore writes never escape the test.
 *
 * @internal
 */
#[CoversClass(SodiumEnvelopeEncryptor::class)]
#[CoversClass(DbalKeystore::class)]
final class SodiumEnvelopeEncryptorKeystoreFunctionalTest extends KernelTestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    private const string AAD = 'iban|new|018f-row';

    public function testItCryptoShredsAValueAgainstARealKeystore(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $scope = EncryptionScopeId::forBankAccount(Uuid::generate());
            $ciphertext = $this->encryptorOn($connection)->encrypt($scope, 'ES9121000418450200051332', self::AAD);

            $this->assertSame(
                'ES9121000418450200051332',
                $this->encryptorOn($connection)->decrypt($scope, $ciphertext, self::AAD),
                'a fresh encryptor reads the persisted DEK',
            );

            $this->encryptorOn($connection)->destroyScope($scope);

            $this->expectException(DekDestroyed::class);

            $this->encryptorOn($connection)->decrypt($scope, $ciphertext, self::AAD);
        });
    }

    private function encryptorOn(Connection $connection): SodiumEnvelopeEncryptor
    {
        return new SodiumEnvelopeEncryptor(new DbalKeystore($connection), self::KEK);
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
