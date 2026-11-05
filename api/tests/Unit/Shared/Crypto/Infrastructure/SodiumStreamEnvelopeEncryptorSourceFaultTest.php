<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\StreamTransferFailed;
use Erpify\Shared\Crypto\Domain\KeyState;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumStreamEnvelopeEncryptor;
use Erpify\Tests\Double\Stream\SealedLayout;
use Erpify\Tests\Double\Stream\SyntheticSink;
use Erpify\Tests\Double\Stream\SyntheticStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How the stream seal and unseal behave over a source that does not behave: one that hands back a byte at a
 * time, one that answers nothing and then something, one that never answers, and one that fails after the key
 * already exists.
 *
 * @internal
 */
#[CoversClass(SodiumStreamEnvelopeEncryptor::class)]
#[CoversClass(ScopeKeyring::class)]
final class SodiumStreamEnvelopeEncryptorSourceFaultTest extends TestCase
{
    use StreamSealingFixture;

    /** Spans two chunks, so the read-ahead and the chunk boundary both meet a short read. */
    private const int TWO_CHUNKS = SealedLayout::CHUNK + 10;

    #[Test]
    public function aSourceHandingBackOneByteAtATimeRoundTripsByteIdentical(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->memory();

        $source = SyntheticStream::source(self::TWO_CHUNKS, maxBytesPerRead: 1);
        $this->encryptor->seal($scope, $source, $sealed, self::AAD);
        $sealedBytes = $this->contents($sealed);

        $this->assertSame(
            SealedLayout::PREFIX_BYTES + self::TWO_CHUNKS + 2 * SealedLayout::TAG_BYTES,
            \strlen($sealedBytes),
        );

        $sink = new SyntheticSink();
        $this->encryptor->unseal(
            $scope,
            SyntheticStream::over($sealedBytes, maxBytesPerRead: 1),
            SyntheticStream::sink($sink),
            self::AAD,
        );

        $this->assertSame(self::TWO_CHUNKS, $sink->bytes());
        $this->assertSame(SyntheticStream::digestOf(self::TWO_CHUNKS), $sink->digest());
    }

    #[Test]
    public function aSourceThatAnswersNothingBeforeItsDataStillRoundTrips(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->memory();

        $this->encryptor->seal($scope, SyntheticStream::source(1000, emptyReadsFirst: 2), $sealed, self::AAD);

        $sink = new SyntheticSink();
        $this->encryptor->unseal(
            $scope,
            SyntheticStream::over($this->contents($sealed), emptyReadsFirst: 2),
            SyntheticStream::sink($sink),
            self::AAD,
        );

        $this->assertSame(SyntheticStream::digestOf(1000), $sink->digest());
    }

    #[Test]
    public function aSourceThatNeverAnswersIsRefusedBeforeAnyKeyIsMinted(): void
    {
        $scope = $this->documentScope();

        try {
            $this->encryptor->seal($scope, SyntheticStream::source(1000, stallsAfter: 0), $this->memory(), self::AAD);
            $this->fail('A source that never answers must not be waited on for ever.');
        } catch (StreamTransferFailed) {
            $this->assertSame(KeyState::Absent, $this->keystore->lockState($scope));
        }
    }

    #[Test]
    public function aSourceThatStallsMidStreamLeavesALiveKeyAndNoFinalChunk(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->memory();

        try {
            $this->encryptor->seal(
                $scope,
                SyntheticStream::source(3 * SealedLayout::CHUNK, stallsAfter: SealedLayout::CHUNK + 5),
                $sealed,
                self::AAD,
            );
            $this->fail('A source that stalls must be refused.');
        } catch (StreamTransferFailed) {
            $this->assertSame(KeyState::Live, $this->keystore->lockState($scope), 'the key was minted on chunk one');
        }

        $this->assertNoCompleteObject($scope, $this->contents($sealed));
    }

    #[Test]
    public function aSourceFailingOnTheSecondChunkLeavesALiveKeyAndNoFinalChunk(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->memory();

        try {
            $this->encryptor->seal(
                $scope,
                SyntheticStream::source(3 * SealedLayout::CHUNK, SealedLayout::CHUNK + 100),
                $sealed,
                self::AAD,
            );
            $this->fail('The source fails on its second chunk.');
        } catch (RuntimeException) {
            $this->assertSame(KeyState::Live, $this->keystore->lockState($scope), 'the key was minted on chunk one');
        }

        $this->assertNoCompleteObject($scope, $this->contents($sealed));
    }

    #[Test]
    public function aSealedSourceThatFailsMidStreamSurfacesItsFailureAndLeavesAPrefixAtMost(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->sealedBytes($scope, \str_repeat('a', 3 * SealedLayout::CHUNK));
        $plaintext = new SyntheticSink();

        try {
            $this->encryptor->unseal(
                $scope,
                SyntheticStream::over($sealed, SealedLayout::PREFIX_BYTES + SealedLayout::RECORD_BYTES + 10),
                SyntheticStream::sink($plaintext),
                self::AAD,
            );
            $this->fail('The source fails after its first chunk.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('The source ran past its declared size.', $runtimeException->getMessage());
        }

        $this->assertLessThan(3 * SealedLayout::CHUNK, $plaintext->bytes(), 'never the whole object');
    }

    #[Test]
    public function aSealedSourceThatStallsIsRefused(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->sealedBytes($scope, \str_repeat('a', 3 * SealedLayout::CHUNK));

        $this->expectException(StreamTransferFailed::class);

        $this->encryptor->unseal(
            $scope,
            SyntheticStream::over($sealed, stallsAfter: SealedLayout::PREFIX_BYTES + SealedLayout::RECORD_BYTES + 10),
            SyntheticStream::sink(new SyntheticSink()),
            self::AAD,
        );
    }

    private function assertNoCompleteObject(EncryptionScopeId $scope, string $partial): void
    {
        try {
            $this->opened($scope, $partial);
            $this->fail('What a failed seal left behind must not open as a whole object.');
        } catch (DecryptionFailed) {
            $this->addToAssertionCount(1);
        }
    }
}
