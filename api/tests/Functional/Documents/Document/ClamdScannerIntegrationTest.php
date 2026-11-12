<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\Scanning\ScanVerdict;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Documents\Document\Infrastructure\Scanning\ClamdScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The adapter against a REAL clamd, which is the only place its configuration can be shown to do what
 * `DocumentScannerLimitsGateTest` reads it to say. Opt-in: it needs the `scanner` compose profile up and its
 * signatures downloaded, so `make php.scanner.integration` starts it, sets `RUN_SCANNER_INTEGRATION` and runs this
 * group. Without that variable every case is skipped, and the default suite never reaches a network scanner.
 *
 * What it measures that nothing else does: that exactly `MAX_ORIGINAL_BYTES` is accepted and one byte more is a limit
 * answer, and that an archive past `MaxFiles` or `MaxRecursion` is a limit answer and not a clean one.
 *
 * @internal
 */
#[Group('scanner')]
#[CoversClass(ClamdScanner::class)]
final class ClamdScannerIntegrationTest extends TestCase
{
    // Two halves, so the literal an endpoint antivirus flags is never a line of this file.
    private const string EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    private ClamdScanner $scanner;

    protected function setUp(): void
    {
        if ('1' !== \getenv('RUN_SCANNER_INTEGRATION')) {
            $this->markTestSkipped('Opt-in: run `make php.scanner.integration` (starts the real clamd).');
        }

        $address = \getenv('DOCUMENTS_SCANNER_ADDRESS');
        $this->scanner = new ClamdScanner(\is_string($address) && '' !== $address ? $address : 'tcp://scanner:3310');
    }

    #[Test]
    public function theDaemonAnswersItsProbe(): void
    {
        $this->assertTrue($this->scanner->probe());
    }

    #[Test]
    public function aCleanStreamIsCleanAndTheEicarStringIsInfected(): void
    {
        $this->assertSame(ScanVerdict::Clean, $this->scan(\random_bytes(1_048_576)));
        $this->assertSame(ScanVerdict::Infected, $this->scan(self::EICAR));
    }

    #[Test]
    public function theLargestAcceptedOriginalIsScannedAndOneByteMoreIsALimitAnswer(): void
    {
        $this->assertSame(ScanVerdict::Clean, $this->scanOfSize(UploadLimits::MAX_ORIGINAL_BYTES));
        $this->assertSame(ScanVerdict::LimitExceeded, $this->scanOfSize(UploadLimits::MAX_ORIGINAL_BYTES + 1));
    }

    #[Test]
    public function anArchiveBeyondTheFileCountOrRecursionLimitIsALimitAnswerAndNeverClean(): void
    {
        $this->assertSame(ScanVerdict::LimitExceeded, $this->scan($this->zipWithEntries(10_100)));
        $this->assertSame(ScanVerdict::LimitExceeded, $this->scan($this->zipNestedDeep(25)));
    }

    private function scan(string $bytes): ScanVerdict
    {
        return $this->scanner->scan(
            static function ($sink) use ($bytes): void {
                foreach (\str_split($bytes, 65_536) as $chunk) {
                    \fwrite($sink, $chunk);
                }
            },
            static fn (): bool => true,
        );
    }

    private function scanOfSize(int $size): ScanVerdict
    {
        $block = \random_bytes(65_536);

        return $this->scanner->scan(
            static function ($sink) use ($size, $block): void {
                for ($left = $size; $left > 0; $left -= 65_536) {
                    \fwrite($sink, $left >= 65_536 ? $block : \substr($block, 0, $left));
                }
            },
            static fn (): bool => true,
        );
    }

    private function zipWithEntries(int $entries): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'zip');
        $this->assertIsString($path);
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);

        for ($index = 0; $index < $entries; ++$index) {
            $zip->addFromString('f' . $index . '.txt', 'a');
        }

        $zip->close();
        $bytes = (string) \file_get_contents($path);
        \unlink($path);

        return $bytes;
    }

    private function zipNestedDeep(int $depth): string
    {
        $inner = 'hello';

        for ($level = 0; $level < $depth; ++$level) {
            $path = \tempnam(\sys_get_temp_dir(), 'zip');
            $this->assertIsString($path);
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::OVERWRITE);
            $zip->addFromString(0 === $level ? 'n.txt' : 'n.zip', $inner);
            $zip->close();
            $inner = (string) \file_get_contents($path);
            \unlink($path);
        }

        return $inner;
    }
}
