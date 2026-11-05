<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Shared\Crypto\Infrastructure\SodiumStreamEnvelopeEncryptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a sealed stream is bound to: the scope, the caller's context and the format version, each refused when
 * it is the only thing that differs, and the separation from the string ciphertexts sealed under the same scope.
 *
 * @internal
 */
#[CoversClass(SodiumStreamEnvelopeEncryptor::class)]
#[CoversClass(ScopeKeyring::class)]
final class SodiumStreamEnvelopeEncryptorBindingTest extends TestCase
{
    use StreamSealingFixture;

    #[Test]
    public function itRefusesAnObjectUnderAnotherScopeEvenWhenBothScopesShareOneKey(): void
    {
        $scope = $this->documentScope();
        $other = $this->documentScope();
        $sealed = $this->sealedBytes($scope, 'evidence');

        // With one raw key behind both scopes, the scope bound into the additional-authenticated-data is the
        // only thing left that can refuse the object; with different keys any open fails on the key alone.
        $this->wrapTheKeyOfUnder($scope, $other);

        $this->expectException(DecryptionFailed::class);

        $this->encryptor->unseal($other, $this->memory($sealed), $this->memory(), self::AAD);
    }

    #[Test]
    public function itRefusesAnObjectUnderAnotherContext(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->sealedBytes($scope, 'evidence');

        $this->expectException(DecryptionFailed::class);

        $this->encryptor->unseal($scope, $this->memory($sealed), $this->memory(), 'original|v2');
    }

    #[Test]
    public function theFormatVersionIsBoundIntoTheAuthenticatedData(): void
    {
        $scope = $this->documentScope();
        $keyring = new ScopeKeyring($this->keystore, self::KEK);
        $dek = $keyring->keyForSealing($scope);

        // A well-formed object in every respect but one: its chunks were sealed without the version in their
        // additional-authenticated-data.
        /** @var array{0: string, 1: string} $initialised */
        $initialised = \sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);
        [$state, $header] = $initialised;
        $record = \sodium_crypto_secretstream_xchacha20poly1305_push(
            $state,
            'evidence',
            $keyring->aad($scope, self::AAD),
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
        );

        $this->expectException(DecryptionFailed::class);

        $this->encryptor->unseal($scope, $this->memory("\x01" . $header . $record), $this->memory(), self::AAD);
    }

    #[Test]
    public function aFilenameCiphertextDoesNotOpenAsAStreamObject(): void
    {
        $scope = $this->documentScope();
        $this->sealedBytes($scope, 'evidence'); // gives the scope its key
        $strings = new SodiumEnvelopeEncryptor(new ScopeKeyring($this->keystore, self::KEK));
        $filename = $strings->encrypt($scope, 'invoice.pdf', self::AAD);
        $raw = \sodium_base642bin($filename, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        foreach ([$filename, $raw] as $bytes) {
            $this->assertRefused(
                fn () => $this->encryptor->unseal($scope, $this->memory($bytes), $this->memory(), self::AAD),
            );
        }
    }

    #[Test]
    public function aStreamObjectDoesNotOpenAsAStringCiphertext(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->sealedBytes($scope, 'invoice.pdf');
        $strings = new SodiumEnvelopeEncryptor(new ScopeKeyring($this->keystore, self::KEK));
        $encoded = \sodium_bin2base64($sealed, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        foreach ([$sealed, $encoded] as $token) {
            $this->assertRefused(static fn (): string => $strings->decrypt($scope, $token, self::AAD));
        }
    }

    /**
     * Gives `$target` the very key `$source` holds, wrapped for its own scope.
     */
    private function wrapTheKeyOfUnder(EncryptionScopeId $source, EncryptionScopeId $target): void
    {
        $dek = (new ScopeKeyring($this->keystore, self::KEK))->keyForOpening($source);
        $nonce = \random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $wrapped = $nonce . \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $dek,
            $target->toString(),
            $nonce,
            self::KEK,
        );

        $this->keystore->store($target, new WrappedDek($wrapped, 1));
    }

    /**
     * @param callable(): mixed $attempt
     */
    private function assertRefused(callable $attempt): void
    {
        try {
            $attempt();
        } catch (DecryptionFailed) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The object was opened where it must be refused.');
    }
}
