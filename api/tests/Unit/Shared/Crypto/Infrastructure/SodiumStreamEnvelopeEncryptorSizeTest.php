<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumStreamEnvelopeEncryptor;
use Erpify\Tests\Support\Stream\SealedLayout;
use Erpify\Tests\Support\Stream\SyntheticSink;
use Erpify\Tests\Support\Stream\SyntheticStream;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The stream seal round-trips byte-identical at the edges of the chunk size and of the size cap, ends its object on a
 * decided final chunk, and holds peak memory flat as the original grows.
 *
 * @internal
 */
#[CoversClass(SodiumStreamEnvelopeEncryptor::class)]
#[CoversClass(ScopeKeyring::class)]
final class SodiumStreamEnvelopeEncryptorSizeTest extends TestCase
{
    use StreamSealingFixture;

    /**
     * Measured, not derived: seal peaks at ~340 KB above baseline for 1 MiB and ~217 KB for 100 MiB (three
     * 64 KiB buffers plus allocator granularity; the first run in a process pays to warm the allocator), so
     * the difference measures 128 KiB; opening peaks at ~218 KB for both. The bound is twice the measured
     * difference, far under the 99 MiB a buffering implementation would add.
     */
    private const int PEAK_GROWTH_BOUND_BYTES = 262_144;

    #[Test]
    #[DataProvider('provideItRoundTripsByteIdenticalAtEverySizeCases')]
    public function itRoundTripsByteIdenticalAtEverySize(int $size): void
    {
        $scope = $this->documentScope();
        $sealed = \tmpfile();
        $this->assertIsResource($sealed);

        $this->encryptor->seal($scope, SyntheticStream::source($size), $sealed, self::AAD);

        $chunks = (int) \ceil($size / SealedLayout::CHUNK);
        $this->assertSame(
            SealedLayout::PREFIX_BYTES + $size + $chunks * SealedLayout::TAG_BYTES,
            \fstat($sealed)['size'] ?? null,
            'no empty trailing chunk: an exact multiple ends in a full final chunk',
        );

        $sink = new SyntheticSink();
        \rewind($sealed);
        $this->encryptor->unseal($scope, $sealed, SyntheticStream::sink($sink), self::AAD);

        $this->assertSame($size, $sink->bytes());
        $this->assertSame(SyntheticStream::digestOf($size), $sink->digest());
    }

    /**
     * @return Generator<string, array{int}>
     */
    public static function provideItRoundTripsByteIdenticalAtEverySizeCases(): iterable
    {
        yield '1 byte' => [1];
        yield 'one byte under a chunk' => [SealedLayout::CHUNK - 1];
        yield 'exactly a chunk' => [SealedLayout::CHUNK];
        yield 'one byte over a chunk' => [SealedLayout::CHUNK + 1];
        yield 'exactly two chunks' => [2 * SealedLayout::CHUNK];
        yield 'two chunks and a byte' => [2 * SealedLayout::CHUNK + 1];
        yield '1 MiB' => [1_048_576];
        yield 'MAX_ORIGINAL_BYTES - 1' => [SealedLayout::MAX_ORIGINAL_BYTES - 1];
        yield 'MAX_ORIGINAL_BYTES' => [SealedLayout::MAX_ORIGINAL_BYTES];
    }

    #[Test]
    public function peakMemoryDoesNotGrowWithTheSizeOfTheOriginal(): void
    {
        $scope = $this->documentScope();
        $this->sealedBytes($scope, 'warm-up: loads every class and primes the allocator');

        [$sealSmall, $unsealSmall] = $this->peaksOf($scope, 1_048_576);
        [$sealLarge, $unsealLarge] = $this->peaksOf($scope, SealedLayout::MAX_ORIGINAL_BYTES);

        \fwrite(STDERR, \sprintf(
            "\npeak bytes above baseline: seal 1 MiB=%d, seal 100 MiB=%d, unseal 1 MiB=%d, unseal 100 MiB=%d\n",
            $sealSmall,
            $sealLarge,
            $unsealSmall,
            $unsealLarge,
        ));

        $this->assertLessThan(self::PEAK_GROWTH_BOUND_BYTES, \abs($sealLarge - $sealSmall));
        $this->assertLessThan(self::PEAK_GROWTH_BOUND_BYTES, \abs($unsealLarge - $unsealSmall));
    }

    #[Test]
    public function aSourceThatRunsPastTheCapAtTheFirstExtraByteSealsNoFinalChunk(): void
    {
        $scope = $this->documentScope();
        $sealed = \tmpfile();
        $this->assertIsResource($sealed);

        try {
            $this->encryptor->seal(
                $scope,
                SyntheticStream::source(SealedLayout::MAX_ORIGINAL_BYTES + 1, SealedLayout::MAX_ORIGINAL_BYTES),
                $sealed,
                self::AAD,
            );
            $this->fail('The counter rejects byte MAX_ORIGINAL_BYTES + 1.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('The source ran past its declared size.', $runtimeException->getMessage());
        }

        // The last full chunk ends exactly at the cap, and only the read-ahead past it hit the counter: that
        // chunk was never sealed, let alone marked final, so what was written cannot open as a whole object.
        $this->assertSame(
            SealedLayout::PREFIX_BYTES
                + (SealedLayout::MAX_ORIGINAL_BYTES / SealedLayout::CHUNK - 1) * SealedLayout::RECORD_BYTES,
            \fstat($sealed)['size'] ?? null,
        );

        \rewind($sealed);
        $this->expectException(DecryptionFailed::class);

        $this->encryptor->unseal($scope, $sealed, SyntheticStream::sink(new SyntheticSink()), self::AAD);
    }

    #[Test]
    public function twoSealsOfTheSameBytesDifferAndBothOpen(): void
    {
        $scope = $this->documentScope();

        $first = $this->sealedBytes($scope, 'same bytes');
        $second = $this->sealedBytes($scope, 'same bytes');

        $this->assertNotSame($first, $second);
        $this->assertSame('same bytes', $this->opened($scope, $first));
        $this->assertSame('same bytes', $this->opened($scope, $second));
    }

    #[Test]
    public function theSealedFormCarriesNoStoragePathSoAMovedObjectStillOpens(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->sealedBytes($scope, 'evidence');

        // The object is just bytes: copied through another stream (a rename, a move, a restore) it opens.
        $copy = $this->memory();
        \stream_copy_to_stream($this->memory($sealed), $copy);

        $this->assertSame('evidence', $this->opened($scope, $this->contents($copy)));
    }

    /**
     * @return array{int, int} the peak memory (bytes above the starting point) of sealing, then of opening
     */
    private function peaksOf(EncryptionScopeId $scope, int $size): array
    {
        $sealed = \tmpfile();
        $this->assertIsResource($sealed);
        $source = SyntheticStream::source($size);
        $sink = SyntheticStream::sink(new SyntheticSink());

        \gc_collect_cycles();
        $before = \memory_get_usage();
        \memory_reset_peak_usage();
        $this->encryptor->seal($scope, $source, $sealed, self::AAD);
        $sealPeak = \memory_get_peak_usage() - $before;

        \rewind($sealed);
        \gc_collect_cycles();
        $before = \memory_get_usage();
        \memory_reset_peak_usage();
        $this->encryptor->unseal($scope, $sealed, $sink, self::AAD);
        $unsealPeak = \memory_get_peak_usage() - $before;

        return [$sealPeak, $unsealPeak];
    }
}
