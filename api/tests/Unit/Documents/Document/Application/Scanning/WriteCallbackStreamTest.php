<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application\Scanning;

use Erpify\Documents\Document\Application\Scanning\WriteCallbackStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The write-only stream the decryptor pushes plaintext into: every write reaches the callback in order, a refusal
 * is zero bytes written (which the sealer turns into a transfer failure), and a callback that throws cannot take the
 * exception across the stream boundary.
 *
 * @internal
 */
#[CoversClass(WriteCallbackStream::class)]
final class WriteCallbackStreamTest extends TestCase
{
    #[Test]
    public function everyWriteReachesTheCallbackInOrderAndIsReportedWritten(): void
    {
        $seen = [];
        $stream = WriteCallbackStream::open(static function (string $bytes) use (&$seen): bool {
            $seen[] = $bytes;

            return true;
        });

        $this->assertSame(3, \fwrite($stream, 'abc'));
        $this->assertSame(2, \fwrite($stream, 'de'));
        $this->assertTrue(\fflush($stream));
        \fclose($stream);

        $this->assertSame(['abc', 'de'], $seen);
    }

    #[Test]
    public function aRefusalIsZeroBytesWritten(): void
    {
        $stream = WriteCallbackStream::open(static fn (): bool => false);

        $this->assertContains(\fwrite($stream, 'abc'), [0, false]);
        \fclose($stream);
    }

    #[Test]
    public function aCallbackThatThrowsIsZeroBytesWrittenAndTheExceptionDoesNotEscape(): void
    {
        $stream = WriteCallbackStream::open(static function (): bool {
            throw new RuntimeException('the sink broke');
        });

        $this->assertContains(\fwrite($stream, 'abc'), [0, false]);
        \fclose($stream);
    }

    #[Test]
    public function aTokenNobodyRegisteredCannotBeOpened(): void
    {
        \set_error_handler(static fn (): bool => true);

        try {
            $this->assertFalse(\fopen('erpify-write-callback://not-a-registered-token', 'w'));
        } finally {
            \restore_error_handler();
        }
    }
}
