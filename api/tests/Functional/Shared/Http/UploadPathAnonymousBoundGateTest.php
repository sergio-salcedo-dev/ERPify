<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Http;

use CurlHandle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The evidence PUT's 100 MiB body bound is lifted only for a request carrying a session cookie. The server
 * receives a body whole before it answers, at the client's pace, so without that condition an anonymous client
 * could hold a worker thread for as long as it trickled 100 MiB, ahead of authentication.
 *
 * Asserted as an effect through the running Caddy, on its own access log: an anonymous over-1MB PUT to the
 * canonical upload path is read only up to the generic 1MB bound, while the same PUT carrying a session cookie —
 * any value: the application, not Caddy, judges the session — is read whole. The second is the control that makes
 * the first mean the bound, not a short body.
 *
 * @internal
 */
#[CoversNothing]
final class UploadPathAnonymousBoundGateTest extends TestCase
{
    private const string PATH = '/api/v1/documents/uploads/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c/content?filename=x';

    private const int BODY_BYTES = 3_000_000;

    /** Caddy's `1MB` is 10^6 bytes; it stops reading one byte past it. */
    private const int GENERIC_BOUND_BYTES = 1_000_001;

    private const string ACCESS_LOG = '/var/log/caddy/access.log';

    private const int FLUSH_TIMEOUT_MICROSECONDS = 10_000_000;

    private const int FLUSH_POLL_MICROSECONDS = 100_000;

    #[Test]
    public function anAnonymousPutIsReadOnlyToTheGenericBound(): void
    {
        $this->assertLessThanOrEqual(
            self::GENERIC_BOUND_BYTES,
            $this->bytesReadFor([]),
            'an anonymous PUT on the upload path was read past the 1MB bound',
        );
    }

    #[Test]
    public function aPutCarryingASessionCookieIsReadWhole(): void
    {
        $this->assertSame(self::BODY_BYTES, $this->bytesReadFor(['Cookie: PHPSESSID=any-value']));
    }

    /**
     * @param list<string> $headers
     */
    private function bytesReadFor(array $headers): int
    {
        $anchor = 'bound-probe-' . \bin2hex(\random_bytes(8));
        $offset = $this->accessLogSize();
        $handle = \curl_init('https://localhost' . self::PATH);

        if (!$handle instanceof CurlHandle) {
            throw new RuntimeException('Could not initialise the probe.');
        }

        \curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => \str_repeat('b', self::BODY_BYTES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => $anchor,
            CURLOPT_HTTPHEADER => [...$headers, 'Content-Type: application/octet-stream', 'Expect:'],
        ]);
        \curl_exec($handle);

        return $this->bytesReadInLine($this->accessLogLine($offset, $anchor));
    }

    private function bytesReadInLine(string $line): int
    {
        if (1 !== \preg_match('/"bytes_read":(\d+)/', $line, $matches)) {
            throw new RuntimeException("The probe's access-log line records no bytes_read.");
        }

        return (int) $matches[1];
    }

    private function accessLogSize(): int
    {
        if (!\is_readable(self::ACCESS_LOG)) {
            throw new RuntimeException('The access log is unreadable; run this inside the php container.');
        }

        \clearstatcache(true, self::ACCESS_LOG);

        return (int) \filesize(self::ACCESS_LOG);
    }

    private function accessLogLine(int $offset, string $anchor): string
    {
        for ($waited = 0; $waited < self::FLUSH_TIMEOUT_MICROSECONDS; $waited += self::FLUSH_POLL_MICROSECONDS) {
            \clearstatcache(true, self::ACCESS_LOG);
            $size = (int) \filesize(self::ACCESS_LOG);
            $written = (string) \file_get_contents(self::ACCESS_LOG, false, null, $size < $offset ? 0 : $offset);

            foreach (\explode("\n", $written) as $line) {
                if (\str_contains($line, $anchor)) {
                    return $line;
                }
            }

            \usleep(self::FLUSH_POLL_MICROSECONDS);
        }

        throw new RuntimeException(\sprintf('No access-log line anchored on "%s" arrived.', $anchor));
    }
}
