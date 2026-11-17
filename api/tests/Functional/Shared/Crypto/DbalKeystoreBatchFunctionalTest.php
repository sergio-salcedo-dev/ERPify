<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Crypto\Application\SealedValue;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Support\Dig;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The batch read of live keys against a real Postgres: only the live scopes come back, a corrupt live row degrades its
 * own entry and no other, and many scopes cost one statement.
 *
 * Runs inside a transaction that is always rolled back, so writes never escape the test.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the keystore, the keyring, the encryptor and their values
 */
#[CoversClass(DbalKeystore::class)]
#[CoversClass(ScopeKeyring::class)]
#[CoversClass(SodiumEnvelopeEncryptor::class)]
final class DbalKeystoreBatchFunctionalTest extends KernelTestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    public function testOnlyLiveScopesComeBackAndACorruptRowDegradesItsOwnEntry(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $keystore = new DbalKeystore($connection);
            $live = $this->scope();
            $destroyed = $this->scope();
            $neverMinted = $this->scope();
            $corrupt = $this->scope();
            $keystore->store($live, new WrappedDek('live-bytes', 1));
            $keystore->store($destroyed, new WrappedDek('doomed-bytes', 1));
            $keystore->destroy($destroyed, DestroyReason::Erasure);
            // A live row with no key material: corruption, not a shredded scope.
            $connection->executeStatement(
                'INSERT INTO dek_keystore (encryption_scope_id, wrapped_dek, kek_version, created_at) '
                . 'VALUES (:scope, NULL, 1, NOW())',
                ['scope' => $corrupt->toString()],
            );

            $found = $keystore->wrappedDeksFor([$live, $destroyed, $neverMinted, $corrupt]);

            $this->assertEqualsCanonicalizing([$live->toString(), $corrupt->toString()], \array_keys($found));
            $wrapped = Dig::value($found, $live->toString());
            $this->assertInstanceOf(WrappedDek::class, $wrapped);
            $this->assertSame('live-bytes', $wrapped->bytes);
            $this->assertInstanceOf(DecryptionFailed::class, Dig::value($found, $corrupt->toString()));
            $this->assertSame([], $keystore->wrappedDeksFor([]));
        });
    }

    public function testManyScopesOpenThroughTheEncryptorWithOneDestroyedAmongThem(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $keystore = new DbalKeystore($connection);
            $encryptor = new SodiumEnvelopeEncryptor(new ScopeKeyring($keystore, self::KEK));
            $values = [];

            for ($i = 0; $i < 20; ++$i) {
                $scope = $this->scope();
                $values[$scope->id] = new SealedValue($scope, $encryptor->encrypt($scope, 'name-' . $i, 'aad'), 'aad');
            }

            $first = \array_key_first($values);
            $encryptor->destroyScope($values[$first]->scope, DestroyReason::Erasure);

            $opened = $encryptor->decryptMany($values);

            $this->assertCount(20, $opened);
            $this->assertInstanceOf(DekDestroyed::class, Dig::value($opened, $first));
            $this->assertCount(19, \array_filter($opened, \is_string(...)));
        });
    }

    public function testACorruptRowAndKeyBytesThatDoNotUnwrapFailOnTheirOwnNextToAHealthyScope(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $keystore = new DbalKeystore($connection);
            $encryptor = new SodiumEnvelopeEncryptor(new ScopeKeyring($keystore, self::KEK));
            $healthy = $this->scope();
            $corrupt = $this->scope();
            $garbage = $this->scope();
            $wrongVersion = $this->scope();
            $token = $encryptor->encrypt($healthy, 'plano.pdf', 'aad');
            $insert = 'INSERT INTO dek_keystore (encryption_scope_id, wrapped_dek, kek_version, created_at) '
                . 'VALUES (:scope, :wrapped, :version, NOW())';
            $connection->executeStatement(
                $insert,
                ['scope' => $corrupt->toString(), 'wrapped' => null, 'version' => 1],
            );
            $connection->executeStatement($insert, [
                'scope' => $garbage->toString(),
                'wrapped' => \sodium_bin2base64('garbage', SODIUM_BASE64_VARIANT_ORIGINAL),
                'version' => 1,
            ]);
            $connection->executeStatement($insert, [
                'scope' => $wrongVersion->toString(),
                'wrapped' => \sodium_bin2base64(\random_bytes(72), SODIUM_BASE64_VARIANT_ORIGINAL),
                'version' => 2,
            ]);

            $opened = $encryptor->decryptMany([
                'corrupt' => new SealedValue($corrupt, $token, 'aad'),
                'garbage' => new SealedValue($garbage, $token, 'aad'),
                'wrongVersion' => new SealedValue($wrongVersion, $token, 'aad'),
                'healthy' => new SealedValue($healthy, $token, 'aad'),
            ]);

            $this->assertInstanceOf(DecryptionFailed::class, Dig::value($opened, 'corrupt'));
            $this->assertInstanceOf(DecryptionFailed::class, Dig::value($opened, 'garbage'));
            $this->assertInstanceOf(DecryptionFailed::class, Dig::value($opened, 'wrongVersion'));
            $this->assertSame('plano.pdf', Dig::value($opened, 'healthy'));
        });
    }

    private function scope(): EncryptionScopeId
    {
        return EncryptionScopeId::forDocument(Uuid::generate());
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
            $connection->rollBack();
        }
    }
}
