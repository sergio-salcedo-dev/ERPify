<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application\Upload;

use ArrayObject;
use Erpify\Documents\Document\Application\Upload\MeteredStreamWrapper;
use Erpify\Documents\Document\Application\Upload\UploadMeter;
use Erpify\Documents\Document\Domain\UploadLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The counting reader the sealer reads through. A body longer than its Content-Length is cut by the transport and
 * an unequal Content-Length is refused before the claim, so the first-extra-byte abort is only reachable here.
 *
 * @internal
 */
#[CoversClass(UploadMeter::class)]
#[CoversClass(MeteredStreamWrapper::class)]
final class UploadMeterTest extends TestCase
{
    public function testAnExactBodyIsHandedOverWholeAndHashed(): void
    {
        $bytes = \random_bytes(200_000);
        $meter = new UploadMeter($this->source($bytes), \strlen($bytes), static fn (): bool => true);

        $read = \stream_get_contents($meter->open());

        $this->assertSame($bytes, $read);
        $this->assertSame(\strlen($bytes), $meter->bytesRead());
        $this->assertSame(\hash('sha256', $bytes), $meter->digest());
        $this->assertNull($meter->abortReason());
    }

    public function testTheFirstExtraByteIsNeverHandedOver(): void
    {
        $declared = UploadLimits::READ_CHUNK_BYTES + 10;
        $meter = new UploadMeter($this->source(\str_repeat('a', $declared + 1)), $declared, static fn (): bool => true);

        $handed = '';

        while (false !== ($chunk = $meter->read(UploadLimits::READ_CHUNK_BYTES)) && '' !== $chunk) {
            $handed .= $chunk;
        }

        $this->assertSame(UploadMeter::ABORT_OVERFLOW, $meter->abortReason());
        $this->assertLessThanOrEqual($declared, \strlen($handed));
        $this->assertSame(\strlen($handed), $meter->bytesRead());
    }

    public function testAShortBodyEndsCleanlyAndIsCountedShort(): void
    {
        $meter = new UploadMeter($this->source('abc'), 10, static fn (): bool => true);

        $this->assertSame('abc', \stream_get_contents($meter->open()));
        $this->assertSame(3, $meter->bytesRead());
        $this->assertNull($meter->abortReason());
    }

    public function testALostLeaseStopsTheStream(): void
    {
        $clock = 0.0;
        $meter = new UploadMeter(
            $this->source(\str_repeat('a', 300_000)),
            300_000,
            static fn (): bool => false,
            static function () use (&$clock): float {
                $clock += UploadLimits::HEARTBEAT_INTERVAL_SECONDS;

                return $clock;
            },
        );

        $this->assertFalse($meter->read(1024));
        $this->assertSame(UploadMeter::ABORT_LEASE_LOST, $meter->abortReason());
    }

    public function testTheHeartbeatRunsOnlyOncePerInterval(): void
    {
        $beats = new ArrayObject();
        $clock = 0.0;
        $meter = new UploadMeter(
            $this->source(\str_repeat('a', 10_000)),
            10_000,
            static function () use ($beats): bool {
                $beats->append(true);

                return true;
            },
            static function () use (&$clock): float {
                return $clock;
            },
        );

        $meter->read(100);

        $clock = UploadLimits::HEARTBEAT_INTERVAL_SECONDS - 0.1;
        $meter->read(100);
        $this->assertCount(0, $beats);

        $clock = UploadLimits::HEARTBEAT_INTERVAL_SECONDS;
        $meter->read(100);
        $this->assertCount(1, $beats);
    }

    /**
     * @return resource
     */
    private function source(string $bytes)
    {
        $source = \fopen('php://temp', 'w+');
        $this->assertNotFalse($source);
        \fwrite($source, $bytes);
        \rewind($source);

        return $source;
    }
}
