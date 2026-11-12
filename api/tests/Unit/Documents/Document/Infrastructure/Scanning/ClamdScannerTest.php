<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Scanning;

use Erpify\Documents\Document\Domain\Scanning\ScanInterrupted;
use Erpify\Documents\Document\Domain\Scanning\ScannerUnavailable;
use Erpify\Documents\Document\Domain\Scanning\ScanVerdict;
use Erpify\Documents\Document\Infrastructure\Scanning\ClamdScanner;
use Erpify\Tests\Support\Clamd\FakeClamd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The scanner adapter over a real socket: how it frames a stream and, above all, how it classifies each answer. The
 * property that matters is that nothing but `stream: OK` is clean and a limit answer is never infected-or-clean.
 *
 * @internal
 */
#[CoversClass(ClamdScanner::class)]
final class ClamdScannerTest extends TestCase
{
    private ?FakeClamd $clamd = null;

    protected function tearDown(): void
    {
        $this->clamd?->stop();
        $this->clamd = null;
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('provideItClassifiesEachAnswerAfterSendingTheWholeStreamCases')]
    public function itClassifiesEachAnswerAfterSendingTheWholeStream(string $mode, ScanVerdict $expected): void
    {
        $scanner = $this->scannerAnswering($mode);

        $verdict = $scanner->scan(
            static function ($sink): void {
                \fwrite($sink, \str_repeat('a', 70_000));
                \fwrite($sink, 'tail');
            },
            static fn (): bool => true,
        );

        $this->assertSame($expected, $verdict);
    }

    /**
     * @return iterable<string, array{string, ScanVerdict}>
     */
    public static function provideItClassifiesEachAnswerAfterSendingTheWholeStreamCases(): iterable
    {
        yield 'clean' => ['ok', ScanVerdict::Clean];
        yield 'infected' => ['found', ScanVerdict::Infected];
        yield 'an exceeded archive limit is not infected' => ['limit-found', ScanVerdict::LimitExceeded];
        yield 'the stream ceiling is a limit' => ['size', ScanVerdict::LimitExceeded];
    }

    #[Test]
    public function anErrorAnswerIsNoVerdict(): void
    {
        $this->expectException(ScanInterrupted::class);

        $this->scannerAnswering('error')->scan(static function ($sink): void {
            \fwrite($sink, 'x');
        }, static fn (): bool => true);
    }

    #[Test]
    public function aLimitAnswerClamdWroteBeforeClosingIsTheVerdictWhetherTheStreamOrTheTerminatorFailed(): void
    {
        $scanner = $this->scannerAnswering('size-close');

        // The producer ignores the refused writes, as a decryptor that finished would: the terminator is what fails.
        $verdict = $scanner->scan(
            static function ($sink): void {
                \set_error_handler(static fn (): bool => true);

                try {
                    for ($chunk = 0; $chunk < 300; ++$chunk) {
                        \fwrite($sink, \str_repeat('a', 65_536));
                        \usleep(1_000);
                    }
                } finally {
                    \restore_error_handler();
                }
            },
            static fn (): bool => true,
        );

        $this->assertSame(ScanVerdict::LimitExceeded, $verdict);
    }

    #[Test]
    public function aConnectionDroppedWithoutAReplyIsNoVerdict(): void
    {
        $this->expectException(ScanInterrupted::class);

        $this->scannerAnswering('drop')->scan(static function ($sink): void {
            \fwrite($sink, 'x');
        }, static fn (): bool => true);
    }

    #[Test]
    public function aWaitTheCallerStopsEndsWithNoVerdict(): void
    {
        $this->expectException(ScanInterrupted::class);

        // The reply comes after three seconds and the read timeout is shorter, so the poll asks and is told to stop.
        $this->scannerAnswering('slow', readTimeoutSeconds: 1)->scan(static function ($sink): void {
            \fwrite($sink, 'x');
        }, static fn (): bool => false);
    }

    #[Test]
    public function aProducerFailureIsRethrownUnchangedAndNothingIsClassified(): void
    {
        $scanner = $this->scannerAnswering('ok');

        try {
            $scanner->scan(static function (): never {
                throw new RuntimeException('the unseal failed');
            }, static fn (): bool => true);
            $this->fail('the producer failure must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('the unseal failed', $runtimeException->getMessage());
        }
    }

    #[Test]
    public function anUnreachableScannerIsUnavailableBeforeAnyByteAndFailsItsProbe(): void
    {
        $scanner = new ClamdScanner('tcp://127.0.0.1:1');

        $this->assertFalse($scanner->probe());
        $this->expectException(ScannerUnavailable::class);
        $scanner->scan(static function (): never {
            throw new RuntimeException('the producer must not run');
        }, static fn (): bool => true);
    }

    #[Test]
    public function aReachableScannerAnswersItsProbe(): void
    {
        $this->assertTrue($this->scannerAnswering('ok')->probe());
    }

    private function scannerAnswering(string $mode, int $readTimeoutSeconds = 20): ClamdScanner
    {
        $this->clamd = new FakeClamd($mode);

        return new ClamdScanner($this->clamd->address(), $readTimeoutSeconds);
    }
}
