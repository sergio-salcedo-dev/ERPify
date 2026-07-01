<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\Exception\InvalidKek;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Crypto\InMemoryKeystore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SodiumEnvelopeEncryptor::class)]
final class SodiumEnvelopeEncryptorTest extends TestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    #[Test]
    public function itRoundTripsAValue(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());

        $ciphertext = $encryptor->encrypt($scope, 'ES9121000418450200051332');

        $this->assertSame('ES9121000418450200051332', $encryptor->decrypt($scope, $ciphertext));
    }

    #[Test]
    public function itProducesADifferentCiphertextEachCall(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());

        $this->assertNotSame($encryptor->encrypt($scope, 'x'), $encryptor->encrypt($scope, 'x'));
    }

    #[Test]
    public function itSharesTheScopeKeyAcrossInstancesViaTheKeystore(): void
    {
        $keystore = new InMemoryKeystore();
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());

        $ciphertext = (new SodiumEnvelopeEncryptor($keystore, self::KEK))->encrypt($scope, 'secret');

        $this->assertSame('secret', (new SodiumEnvelopeEncryptor($keystore, self::KEK))->decrypt($scope, $ciphertext));
    }

    #[Test]
    public function itCannotDecryptOnceTheScopeIsDestroyed(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());
        $ciphertext = $encryptor->encrypt($scope, 'secret');

        $encryptor->destroyScope($scope);

        $this->expectException(DekDestroyed::class);

        $encryptor->decrypt($scope, $ciphertext);
    }

    #[Test]
    public function itRefusesToEncryptOnceTheScopeIsDestroyed(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());
        $encryptor->encrypt($scope, 'secret');
        $encryptor->destroyScope($scope);

        // Crypto-shredding is irreversible: encrypting under a tombstoned scope must fail loudly, never
        // silently seal under a fresh key that was never persisted and could never be unwrapped again.
        $this->expectException(DekDestroyed::class);

        $encryptor->encrypt($scope, 'resurrected');
    }

    #[Test]
    public function itRejectsAMalformedCiphertext(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());
        $encryptor->encrypt($scope, 'secret');

        $this->expectException(DecryptionFailed::class);

        $encryptor->decrypt($scope, '@@ not valid base64 @@');
    }

    #[Test]
    public function itRejectsACiphertextSealedUnderAnotherScope(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scopeA = EncryptionScopeId::forBankAccount(Uuid::generate());
        $scopeB = EncryptionScopeId::forBankAccount(Uuid::generate());
        $ciphertextA = $encryptor->encrypt($scopeA, 'secret');
        $encryptor->encrypt($scopeB, 'other');

        $this->expectException(DecryptionFailed::class);

        $encryptor->decrypt($scopeB, $ciphertextA);
    }

    #[Test]
    public function itRejectsAKekOfTheWrongLength(): void
    {
        $this->expectException(InvalidKek::class);

        new SodiumEnvelopeEncryptor(new InMemoryKeystore(), 'too-short');
    }
}
