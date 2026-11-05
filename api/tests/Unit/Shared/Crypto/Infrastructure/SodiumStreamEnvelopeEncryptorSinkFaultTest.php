<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Domain\Exception\StreamTransferFailed;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumStreamEnvelopeEncryptor;
use Erpify\Tests\Double\Stream\SealedLayout;
use Erpify\Tests\Double\Stream\SyntheticSink;
use Erpify\Tests\Double\Stream\SyntheticStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How the stream seal and unseal behave over a sink that does not behave: one that takes less than it is
 * given, runs out of room, or cannot be flushed.
 *
 * @internal
 */
#[CoversClass(SodiumStreamEnvelopeEncryptor::class)]
#[CoversClass(ScopeKeyring::class)]
final class SodiumStreamEnvelopeEncryptorSinkFaultTest extends TestCase
{
    use StreamSealingFixture;

    /** Spans two chunks, so the read-ahead and the chunk boundary both meet a short read. */
    private const int TWO_CHUNKS = SealedLayout::CHUNK + 10;

    #[Test]
    public function aSinkThatFailsToFlushAfterTheFinalChunkIsReported(): void
    {
        $scope = $this->documentScope();

        $this->expectException(StreamTransferFailed::class);

        $this->encryptor->seal(
            $scope,
            $this->memory('evidence'),
            SyntheticStream::unflushableSink(new SyntheticSink()),
            self::AAD,
        );
    }

    #[Test]
    public function aPlaintextSinkThatFailsToFlushAfterTheLastChunkIsReported(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->sealedBytes($scope, 'evidence');

        $this->expectException(StreamTransferFailed::class);

        $this->encryptor->unseal(
            $scope,
            $this->memory($sealed),
            SyntheticStream::unflushableSink(new SyntheticSink()),
            self::AAD,
        );
    }

    #[Test]
    public function aSinkAcceptingFewBytesPerWriteStillReceivesTheWholeObject(): void
    {
        $scope = $this->documentScope();
        $sealedSink = new SyntheticSink();

        $this->encryptor->seal(
            $scope,
            SyntheticStream::source(self::TWO_CHUNKS),
            SyntheticStream::sink($sealedSink, maxBytesPerWrite: 7),
            self::AAD,
        );

        $this->assertSame(
            SealedLayout::PREFIX_BYTES + self::TWO_CHUNKS + 2 * SealedLayout::TAG_BYTES,
            $sealedSink->bytes(),
        );

        $sealed = $this->memory();
        $this->encryptor->seal($scope, SyntheticStream::source(self::TWO_CHUNKS), $sealed, self::AAD);
        $plaintext = new SyntheticSink();
        $this->encryptor->unseal(
            $scope,
            $this->memory($this->contents($sealed)),
            SyntheticStream::sink($plaintext, maxBytesPerWrite: 7),
            self::AAD,
        );

        $this->assertSame(SyntheticStream::digestOf(self::TWO_CHUNKS), $plaintext->digest());
    }

    #[Test]
    public function aSinkThatRunsOutOfRoomIsReportedOnSeal(): void
    {
        $scope = $this->documentScope();

        $this->expectException(StreamTransferFailed::class);

        $this->encryptor->seal(
            $scope,
            $this->memory(\str_repeat('a', 1000)),
            SyntheticStream::sink(new SyntheticSink(), capacity: 100),
            self::AAD,
        );
    }

    #[Test]
    public function aSinkThatTakesNothingIsReportedOnSeal(): void
    {
        $scope = $this->documentScope();

        $this->expectException(StreamTransferFailed::class);

        $this->encryptor->seal(
            $scope,
            $this->memory('evidence'),
            SyntheticStream::sink(new SyntheticSink(), capacity: 0),
            self::AAD,
        );
    }

    #[Test]
    public function aSinkThatRunsOutOfRoomIsReportedOnUnseal(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->sealedBytes($scope, \str_repeat('a', 1000));

        $this->expectException(StreamTransferFailed::class);

        $this->encryptor->unseal(
            $scope,
            $this->memory($sealed),
            SyntheticStream::sink(new SyntheticSink(), capacity: 100),
            self::AAD,
        );
    }
}
