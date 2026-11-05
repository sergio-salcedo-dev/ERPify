<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Domain\Exception\CannotSealEmptyStream;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\StreamTransferFailed;
use Erpify\Shared\Crypto\Domain\KeyState;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumStreamEnvelopeEncryptor;
use Erpify\Tests\Support\Stream\SealedLayout;
use Erpify\Tests\Support\Stream\SyntheticStream;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the stream seal and unseal refuse: an empty or failing source, an object altered, cut, extended or opened under
 * another scope or context, and a sink that cannot be written.
 *
 * @internal
 */
#[CoversClass(SodiumStreamEnvelopeEncryptor::class)]
#[CoversClass(ScopeKeyring::class)]
final class SodiumStreamEnvelopeEncryptorRefusalTest extends TestCase
{
    use StreamSealingFixture;

    #[Test]
    public function anEmptyStreamIsRefusedBeforeAnyKeyIsMinted(): void
    {
        $scope = $this->documentScope();

        try {
            $this->encryptor->seal($scope, $this->memory(''), $this->memory(), self::AAD);
            $this->fail('An empty stream must not be sealed.');
        } catch (CannotSealEmptyStream) {
            $this->assertSame(KeyState::Absent, $this->keystore->lockState($scope), 'no keystore row was written');
        }
    }

    #[Test]
    public function aSourceThatFailsBeforeItsFirstByteMintsNoKey(): void
    {
        $scope = $this->documentScope();

        try {
            $this->encryptor->seal($scope, SyntheticStream::source(10, 0), $this->memory(), self::AAD);
            $this->fail('The source fails on its first read.');
        } catch (RuntimeException) {
            $this->assertSame(KeyState::Absent, $this->keystore->lockState($scope));
        }
    }

    /**
     * @param callable(string): string $tamper
     */
    #[Test]
    #[DataProvider('provideItRefusesAnAlteredObjectCases')]
    public function itRefusesAnAlteredObject(callable $tamper): void
    {
        $scope = $this->documentScope();
        // Three chunks (two full, one short), so every tampering above has a chunk to act on.
        $sealed = $this->sealedBytes($scope, \str_repeat('a', 2 * SealedLayout::CHUNK) . 'tail');
        $altered = $tamper($sealed);
        $this->assertNotSame($sealed, $altered);

        $this->expectException(DecryptionFailed::class);

        $this->encryptor->unseal($scope, $this->memory($altered), $this->memory(), self::AAD);
    }

    /**
     * @return Generator<string, array{callable(string): string}>
     */
    public static function provideItRefusesAnAlteredObjectCases(): iterable
    {
        $prefix = SealedLayout::PREFIX_BYTES;
        $record = SealedLayout::RECORD_BYTES;

        yield 'a flipped bit in the first chunk' => [static function (string $sealed) use ($prefix): string {
            $sealed[$prefix + 3] = \chr(\ord($sealed[$prefix + 3]) ^ 1);

            return $sealed;
        }];
        yield 'a flipped bit in the header' => [static function (string $sealed): string {
            $sealed[5] = \chr(\ord($sealed[5]) ^ 1);

            return $sealed;
        }];
        yield 'an unknown format version' => [static function (string $sealed): string {
            $sealed[0] = "\x02";

            return $sealed;
        }];
        yield 'a zero-byte object' => [static fn (string $sealed): string => ''];
        yield 'a header and no chunk' => [static fn (string $sealed): string => \substr($sealed, 0, $prefix)];
        yield 'a header cut short' => [static fn (string $sealed): string => \substr($sealed, 0, 10)];
        yield 'cut at a chunk boundary (no final chunk)' => [
            static fn (string $sealed): string => \substr($sealed, 0, $prefix + $record),
        ];
        yield 'cut in the middle of a chunk' => [
            static fn (string $sealed): string => \substr($sealed, 0, $prefix + $record + 100),
        ];
        yield 'the final chunk removed' => [
            static fn (string $sealed): string => \substr($sealed, 0, \strlen($sealed) - $record),
        ];
        yield 'a middle chunk removed' => [
            static fn (string $sealed): string => \substr($sealed, 0, $prefix + $record)
                . \substr($sealed, $prefix + 2 * $record),
        ];
        yield 'two chunks swapped' => [
            static fn (string $sealed): string => \substr($sealed, 0, $prefix)
                . \substr($sealed, $prefix + $record, $record)
                . \substr($sealed, $prefix, $record)
                . \substr($sealed, $prefix + 2 * $record),
        ];
        yield 'a chunk repeated' => [
            static fn (string $sealed): string => \substr($sealed, 0, $prefix + $record)
                . \substr($sealed, $prefix, $record)
                . \substr($sealed, $prefix + $record),
        ];
        yield 'a byte appended after the final chunk' => [static fn (string $sealed): string => $sealed . "\0"];
        yield 'a whole chunk appended after the final chunk' => [
            static fn (string $sealed): string => $sealed . \substr($sealed, $prefix, $record),
        ];
    }

    #[Test]
    public function itRefusesBytesAfterAFullSizeFinalChunk(): void
    {
        // A short final record absorbs appended bytes into its own authentication, so only a final chunk of
        // exactly the maximum size reaches the end-of-object check.
        $scope = $this->documentScope();
        $sealed = $this->sealedBytes($scope, \str_repeat('a', SealedLayout::CHUNK));
        $this->assertSame(SealedLayout::PREFIX_BYTES + SealedLayout::RECORD_BYTES, \strlen($sealed));

        $this->expectException(DecryptionFailed::class);

        $this->encryptor->unseal($scope, $this->memory($sealed . "\0"), $this->memory(), self::AAD);
    }

    #[Test]
    public function itRefusesAnObjectUnderAnotherScopeOrAnotherContext(): void
    {
        $scope = $this->documentScope();
        $other = $this->documentScope();
        $sealed = $this->sealedBytes($scope, 'evidence');
        $this->sealedBytes($other, 'unrelated'); // gives the other scope a live key of its own

        foreach ([[$other, self::AAD], [$scope, 'original|v2']] as [$openScope, $openAad]) {
            $refused = false;

            try {
                $this->encryptor->unseal($openScope, $this->memory($sealed), $this->memory(), $openAad);
            } catch (DecryptionFailed) {
                $refused = true;
            }

            $this->assertTrue(
                $refused,
                \sprintf('opening under %s with "%s" must fail', $openScope->toString(), $openAad),
            );
        }
    }

    #[Test]
    public function aFailedWriteToTheSinkIsReportedNotSwallowed(): void
    {
        $scope = $this->documentScope();
        $readOnly = \fopen('php://memory', 'r');
        $this->assertIsResource($readOnly);

        $this->expectException(StreamTransferFailed::class);

        $this->encryptor->seal($scope, $this->memory('evidence'), $readOnly, self::AAD);
    }
}
