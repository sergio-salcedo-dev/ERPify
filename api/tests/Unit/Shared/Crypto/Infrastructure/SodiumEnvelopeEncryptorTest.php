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

    private const string AAD = 'holderName|old|018f-row';

    #[Test]
    public function itRoundTripsAValue(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());

        $ciphertext = $encryptor->encrypt($scope, 'ES9121000418450200051332', self::AAD);

        $this->assertSame('ES9121000418450200051332', $encryptor->decrypt($scope, $ciphertext, self::AAD));
    }

    #[Test]
    public function itProducesADifferentCiphertextEachCall(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());

        $this->assertNotSame($encryptor->encrypt($scope, 'x', self::AAD), $encryptor->encrypt($scope, 'x', self::AAD));
    }

    #[Test]
    public function itSharesTheScopeKeyAcrossInstancesViaTheKeystore(): void
    {
        $keystore = new InMemoryKeystore();
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());

        $ciphertext = (new SodiumEnvelopeEncryptor($keystore, self::KEK))->encrypt($scope, 'secret', self::AAD);

        $this->assertSame(
            'secret',
            (new SodiumEnvelopeEncryptor($keystore, self::KEK))->decrypt($scope, $ciphertext, self::AAD),
        );
    }

    #[Test]
    public function itCannotDecryptOnceTheScopeIsDestroyed(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());
        $ciphertext = $encryptor->encrypt($scope, 'secret', self::AAD);

        $encryptor->destroyScope($scope);

        $this->expectException(DekDestroyed::class);

        $encryptor->decrypt($scope, $ciphertext, self::AAD);
    }

    #[Test]
    public function itRefusesToEncryptOnceTheScopeIsDestroyed(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());
        $encryptor->encrypt($scope, 'secret', self::AAD);
        $encryptor->destroyScope($scope);

        // Crypto-shredding is irreversible: encrypting under a tombstoned scope must fail loudly, never
        // silently seal under a fresh key that was never persisted and could never be unwrapped again.
        $this->expectException(DekDestroyed::class);

        $encryptor->encrypt($scope, 'resurrected', self::AAD);
    }

    #[Test]
    public function itRejectsAMalformedCiphertext(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());
        $encryptor->encrypt($scope, 'secret', self::AAD);

        $this->expectException(DecryptionFailed::class);

        $encryptor->decrypt($scope, '@@ not valid base64 @@', self::AAD);
    }

    #[Test]
    public function itRejectsAValidBase64CiphertextTooShortToHoldANonce(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());
        $encryptor->encrypt($scope, 'secret', self::AAD); // mint the DEK so decrypt reaches the AEAD open

        // Valid base64url that decodes to 14 bytes — fewer than the 24-byte nonce — so the AEAD open throws.
        $tooShort = \sodium_bin2base64('under-24-bytes', SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $this->expectException(DecryptionFailed::class);

        $encryptor->decrypt($scope, $tooShort, self::AAD);
    }

    #[Test]
    public function itRejectsACiphertextSealedUnderAnotherScope(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scopeA = EncryptionScopeId::forBankAccount(Uuid::generate());
        $scopeB = EncryptionScopeId::forBankAccount(Uuid::generate());
        $ciphertextA = $encryptor->encrypt($scopeA, 'secret', self::AAD);
        $encryptor->encrypt($scopeB, 'other', self::AAD);

        $this->expectException(DecryptionFailed::class);

        $encryptor->decrypt($scopeB, $ciphertextA, self::AAD);
    }

    #[Test]
    public function itRejectsACiphertextRelocatedToAnotherSlot(): void
    {
        $encryptor = new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK);
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());
        $ciphertext = $encryptor->encrypt($scope, 'ES9121000418450200051332', 'iban|new|018f-row-a');

        // Same scope and key; each variant moves the value to a different slot of the same subject — another
        // field, the other side of the change, or another row — and none may authenticate, because field,
        // position and row id are each bound into the AAD (the three intra-scope relocations the binding closes).
        foreach (['holderName|new|018f-row-a', 'iban|old|018f-row-a', 'iban|new|018f-row-b'] as $relocated) {
            $rejected = false;

            try {
                $encryptor->decrypt($scope, $ciphertext, $relocated);
            } catch (DecryptionFailed) {
                $rejected = true;
            }

            $this->assertTrue(
                $rejected,
                \sprintf('a ciphertext verified under "%s" must not authenticate', $relocated),
            );
        }
    }

    #[Test]
    public function itRejectsAKekOfTheWrongLength(): void
    {
        $this->expectException(InvalidKek::class);

        new SodiumEnvelopeEncryptor(new InMemoryKeystore(), 'too-short');
    }
}
