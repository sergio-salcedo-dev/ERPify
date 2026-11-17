<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Application\SealedValue;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Tests\Support\Dig;
use Erpify\Tests\Unit\Shared\Crypto\CountingKeystore;
use Erpify\Tests\Unit\Shared\Crypto\InMemoryKeystore;
use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `decryptMany()`: one keystore read for every value, and an outcome per value that is exactly what `decrypt()` would
 * have answered for it alone — the plaintext or the exception, never a throw, never one value's failure in another's
 * place.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the encryptor, its keyring, three keystores and their values
 */
#[CoversClass(SodiumEnvelopeEncryptor::class)]
#[CoversClass(ScopeKeyring::class)]
final class SodiumEnvelopeEncryptorDecryptManyTest extends TestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    private const string AAD = 'filename';

    private InMemoryKeystore $memory;

    private CountingKeystore $keystore;

    private SodiumEnvelopeEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->memory = new InMemoryKeystore();
        $this->keystore = new CountingKeystore($this->memory);
        $this->encryptor = new SodiumEnvelopeEncryptor(new ScopeKeyring($this->keystore, self::KEK));
    }

    #[Test]
    public function aMixedBatchAnswersEachValueOnItsOwnWithOneKeystoreRead(): void
    {
        $good = $this->scope('good');
        $destroyed = $this->scope('destroyed');
        $neverMinted = $this->scope('never');
        $tampered = $this->scope('tampered');
        $tamperedToken = $this->encryptor->encrypt($tampered, 'tampered', self::AAD);
        $values = [
            'good' => new SealedValue($good, $this->encryptor->encrypt($good, 'plano.pdf', self::AAD), self::AAD),
            'destroyed' => new SealedValue(
                $destroyed,
                $this->encryptor->encrypt($destroyed, 'x', self::AAD),
                self::AAD,
            ),
            'never' => new SealedValue($neverMinted, 'irrelevant', self::AAD),
            'tampered' => new SealedValue($tampered, $this->flipLastByte($tamperedToken), self::AAD),
            'wrongAad' => new SealedValue($good, $this->encryptor->encrypt($good, 'plano.pdf', self::AAD), 'other'),
        ];
        $this->encryptor->destroyScope($destroyed, DestroyReason::Erasure);
        $before = $this->keystore->reads();

        $opened = $this->encryptor->decryptMany($values);

        $this->assertSame(1, $this->keystore->reads() - $before, 'one keystore read for the whole batch');
        $this->assertSame(1, $this->keystore->batchReads);
        $this->assertSame(['good', 'destroyed', 'never', 'tampered', 'wrongAad'], \array_keys($opened));
        $this->assertSame('plano.pdf', Dig::value($opened, 'good'));
        $this->assertInstanceOf(DekDestroyed::class, Dig::value($opened, 'destroyed'));
        $this->assertInstanceOf(DekDestroyed::class, Dig::value($opened, 'never'));
        $this->assertInstanceOf(DecryptionFailed::class, Dig::value($opened, 'tampered'));
        $this->assertInstanceOf(DecryptionFailed::class, Dig::value($opened, 'wrongAad'));
    }

    #[Test]
    public function aCorruptKeyRowAndKeyBytesThatDoNotUnwrapEachFailOnTheirOwnNextToAHealthyScope(): void
    {
        $healthy = $this->scope('healthy');
        $corruptRow = $this->scope('corrupt-row');
        $garbage = $this->scope('garbage');
        $wrongVersion = $this->scope('wrong-version');
        $token = $this->encryptor->encrypt($healthy, 'plano.pdf', self::AAD);
        $this->memory->corruptInBatchReads($corruptRow, DecryptionFailed::forScope($corruptRow));
        $this->memory->corruptInBatchReads($garbage, new WrappedDek('not a wrapped key', 1));
        $this->memory->corruptInBatchReads($wrongVersion, new WrappedDek(\random_bytes(72), 2));

        $opened = $this->encryptor->decryptMany([
            'corruptRow' => new SealedValue($corruptRow, $token, self::AAD),
            'garbage' => new SealedValue($garbage, $token, self::AAD),
            'wrongVersion' => new SealedValue($wrongVersion, $token, self::AAD),
            'healthy' => new SealedValue($healthy, $token, self::AAD),
        ]);

        $this->assertInstanceOf(DecryptionFailed::class, Dig::value($opened, 'corruptRow'));
        $this->assertInstanceOf(DecryptionFailed::class, Dig::value($opened, 'garbage'));
        $this->assertInstanceOf(DecryptionFailed::class, Dig::value($opened, 'wrongVersion'));
        $this->assertSame('plano.pdf', Dig::value($opened, 'healthy'));
    }

    #[Test]
    public function aFailureThatIsNotADecryptionFailureStillPropagatesWhileTheKeysAlreadyUnwrappedAreWiped(): void
    {
        $healthy = $this->scope('healthy');
        $broken = $this->scope('broken');
        $token = $this->encryptor->encrypt($healthy, 'plano.pdf', self::AAD);
        // An entry that was never initialised: reading it raises an Error, which is no DecryptionFailed.
        $this->memory->corruptInBatchReads(
            $broken,
            (new ReflectionClass(WrappedDek::class))->newInstanceWithoutConstructor(),
        );

        $this->expectException(Error::class);

        $this->encryptor->decryptMany([
            'healthy' => new SealedValue($healthy, $token, self::AAD),
            'broken' => new SealedValue($broken, $token, self::AAD),
        ]);
    }

    #[Test]
    public function eachOutcomeEqualsWhatTheSingleValueDecryptAnswers(): void
    {
        $scope = $this->scope('same');
        $token = $this->encryptor->encrypt($scope, 'same.pdf', self::AAD);

        $many = $this->encryptor->decryptMany(['a' => new SealedValue($scope, $token, self::AAD)]);

        $this->assertSame($this->encryptor->decrypt($scope, $token, self::AAD), Dig::value($many, 'a'));

        $gone = $this->scope('gone');
        $this->assertInstanceOf(
            DekDestroyed::class,
            Dig::value($this->encryptor->decryptMany(['a' => new SealedValue($gone, $token, self::AAD)]), 'a'),
        );
    }

    #[Test]
    public function twoValuesOfOneScopeShareTheScopesSingleKey(): void
    {
        $scope = $this->scope('shared');
        $one = $this->encryptor->encrypt($scope, 'one', self::AAD);
        $two = $this->encryptor->encrypt($scope, 'two', self::AAD);

        $opened = $this->encryptor->decryptMany([
            'one' => new SealedValue($scope, $one, self::AAD),
            'two' => new SealedValue($scope, $two, self::AAD),
        ]);

        $this->assertSame(['one' => 'one', 'two' => 'two'], $opened);
    }

    #[Test]
    public function anEmptyBatchTouchesNothing(): void
    {
        $this->assertSame([], $this->encryptor->decryptMany([]));
        $this->assertSame(0, $this->keystore->singleReads);
    }

    private function scope(string $id): EncryptionScopeId
    {
        return EncryptionScopeId::forDocument($id);
    }

    private function flipLastByte(string $token): string
    {
        $raw = \sodium_base642bin($token, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 0xFF);

        return \sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
