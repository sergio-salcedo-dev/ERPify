<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use CurlHandle;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The evidence PUT carries the caller's original filename, percent-encoded, in its query — so the access log
 * and the container log are the first sinks it reaches, before the application has validated anything. This
 * sends REAL, AUTHENTICATED PUTs through the running Caddy (the sibling `AccessLogQueryContainmentGateTest` is
 * GET-only) in the three shapes a body can end, each against an upload it declared first:
 *  - completed: a body just over 1 MB on the canonical path, which must be filed (201) — so the 1MB bound of
 *    every other route does not apply here;
 *  - aborted mid-body: the client stops sending and the application, reading a body shorter than declared,
 *    aborts the upload (its intent ends `expired`);
 *  - past the bound: one byte more than the route's cap.
 * Each carries a distinctive name, and both sinks are searched for it, encoded and decoded; and once the request
 * has ended, no spool of its body remains in `upload_tmp_dir`.
 *
 * The session is the e2e administrator's, seeded into the dev stack the way `make pwa.test.e2e` seeds it
 * (idempotently, through the console) and signed in through Caddy. Every row a probe creates in the dev database
 * is removed after it, and the completed probe's sealed object with them ({@see DevStackUploadRows}).
 *
 * Two sinks are read: Caddy's access log, and the container's own log. The latter lives on the host (Docker's
 * json-file driver), so the dev entrypoint tees everything PID 1 writes into `CONTAINER_LOG_MIRROR`
 * (compose.dev.yaml) and this reads that copy; it must show the probe arriving (the router records the
 * route path on stderr in dev) before its silence about the name means anything.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyMethods") the probes, their two sinks and the cleanup of the dev rows
 */
#[CoversNothing]
final class UploadFilenameAccessLogContainmentGateTest extends TestCase
{
    private const string ORIGIN = 'https://localhost';

    /** Over Caddy's `1MB` (10^6) and over a mebibyte, so neither reading of the generic bound could admit it. */
    private const int OVER_ONE_MEGABYTE = 1_100_000;

    private const int ABORTED_SIZE = 1_000_000;

    private const int ABORT_AFTER_BYTES = 65_536;

    /** A body refused only after it arrived, sent slower than PHP's default 30 s limit would allow a drain. */
    private const int SLOW_REFUSED_SIZE = 1_300_000;

    private const int SLOW_BYTES_PER_SECOND = 35_000;

    /** The answer each ending must get, the aborted one excepted: its client is gone before any answer. */
    private const array EXPECTED_STATUS = [
        'completed' => 201,
        'gone' => 410,
        'refused slowly' => 410,
        'oversized' => 422,
    ];

    /** @var non-empty-string|null */
    private static ?string $cookieJar = null;

    private static ?string $session = null;

    private DevStackUploadRows $rows;

    /** @var list<string> */
    private array $intents = [];

    /** @var list<string> */
    private array $documents = [];

    public static function setUpBeforeClass(): void
    {
        DevStackUploadRows::prepare();

        $jar = \tempnam(\sys_get_temp_dir(), 'access-log-probe');
        self::$cookieJar = \is_string($jar) ? $jar : null;
        self::$session = DevStackUploadRows::signIn((string) self::$cookieJar);
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$session) {
            DevStackUploadRows::forgetSession(self::$session);
        }

        if (null !== self::$cookieJar && \is_file(self::$cookieJar)) {
            \unlink(self::$cookieJar);
        }
    }

    #[Override]
    protected function setUp(): void
    {
        $this->rows = new DevStackUploadRows();
    }

    protected function tearDown(): void
    {
        $this->rows->remove($this->intents, $this->documents);
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('provideTheNameNeverReachesEitherSinkCases')]
    public function theNameNeverReachesEitherSink(string $ending): void
    {
        $name = 'sentinel-' . \bin2hex(\random_bytes(8)) . ' Acta é.pdf';
        $anchor = 'anchor' . \bin2hex(\random_bytes(10));
        $upload = $this->uploadFor($ending);
        $position = DevStackLogs::accessLogPosition();
        $mirrorOffset = DevStackLogs::mirrorSize();

        $token = \random_bytes(16);
        $status = $this->put($upload, $name, $anchor, $ending, $token);

        $written = DevStackLogs::accessLogWrittenSince($position, $anchor);
        $mirrored = DevStackLogs::mirrorWrittenSince($mirrorOffset);
        $this->assertNoSpoolHolds($token);

        $this->assertEnding($ending, $status, $written, $anchor, $upload);

        $this->assertStringContainsString('documents_upload_content', $mirrored, 'the probe reached the container log');
        $this->assertStringContainsString('/api/v1/documents/uploads/', $written, 'the probe line arrived');

        foreach (
            [
                'the access log' => $written,
                'the container log' => $mirrored,
            ] as $sink => $contents
        ) {
            $this->assertStringNotContainsString(\rawurlencode($name), $contents, $sink . ' holds the encoded name');
            $this->assertStringNotContainsString(\urlencode($name), $contents, $sink . ' holds the form-encoded name');
            $this->assertStringNotContainsString(\explode(' ', $name)[0], $contents, $sink . ' holds the name');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheNameNeverReachesEitherSinkCases(): iterable
    {
        yield 'completed' => ['completed'];
        yield 'aborted mid-body' => ['aborted'];
        yield 'past the bound' => ['oversized'];
        yield 'refused, on a real session' => ['gone'];
        yield "refused, sent slower than PHP's default limit" => ['refused slowly'];
    }

    /**
     * A refused PUT targets an upload that was never declared, so the session is real and the answer is 410.
     */
    private function uploadFor(string $ending): string
    {
        return match ($ending) {
            'gone', 'refused slowly' => '0190e9c2-7b5a-7d40-9c8f-' . \bin2hex(\random_bytes(6)),
            'completed' => $this->declare(self::OVER_ONE_MEGABYTE),
            'aborted' => $this->declare(self::ABORTED_SIZE),
            default => $this->declare(UploadLimits::MAX_ORIGINAL_BYTES),
        };
    }

    private function assertEnding(string $ending, int $status, string $written, string $anchor, string $upload): void
    {
        if (isset(self::EXPECTED_STATUS[$ending])) {
            $this->assertSame(self::EXPECTED_STATUS[$ending], $status, 'the answer the application owes ' . $ending);
        }

        if ('oversized' === $ending) {
            $this->assertLessThanOrEqual(
                UploadLimits::MAX_ORIGINAL_BYTES + 1,
                $this->bytesReadBy($written, $anchor),
                'Caddy read past the upload bound',
            );
        }

        if ('aborted' === $ending) {
            $this->assertSame(
                'expired',
                $this->rows->stateOf($upload),
                'the application aborted the upload whose body ended short',
            );
        }
    }

    private function declare(int $size): string
    {
        [$status, $body] = $this->request('POST', '/api/v1/documents/uploads', \json_encode([
            'size' => $size,
            'retentionReason' => 'technical-file',
            'legalBasis' => 'contract',
        ]) ?: '');
        $this->assertSame(201, $status, $body);
        $decoded = \json_decode($body, true);
        $this->assertIsArray($decoded);
        $id = Dig::string($decoded, 'data', 'id');
        $this->intents[] = $id;

        return $id;
    }

    /**
     * @param non-empty-string $method
     *
     * @return array{int, string} the status and the body of a JSON request through Caddy, on the probe's session
     */
    private function request(string $method, string $path, string $json): array
    {
        $handle = $this->handle(self::ORIGIN . $path);
        \curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . self::ORIGIN],
        ]);
        $body = \curl_exec($handle);

        return [\curl_getinfo($handle, CURLINFO_RESPONSE_CODE), \is_string($body) ? $body : ''];
    }

    /**
     * @param non-empty-string $anchor
     *
     * @return int the status Caddy answered, or 0 when the probe itself ended the exchange
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the read callback's signature is curl's
     */
    private function put(string $upload, string $name, string $anchor, string $ending, string $token): int
    {
        $handle = $this->handle(\sprintf(
            '%s/api/v1/documents/uploads/%s/content?filename=%s',
            self::ORIGIN,
            $upload,
            \rawurlencode($name),
        ));

        $length = match ($ending) {
            'completed', 'gone' => self::OVER_ONE_MEGABYTE,
            'aborted' => self::ABORTED_SIZE,
            'refused slowly' => self::SLOW_REFUSED_SIZE,
            default => UploadLimits::MAX_ORIGINAL_BYTES + 1,
        };
        $abortAfter = 'aborted' === $ending ? self::ABORT_AFTER_BYTES : PHP_INT_MAX;
        $sent = 0;

        // `$curl` and `$stream` are the callback's fixed signature.
        $feed = static function (
            CurlHandle $curl,
            mixed $stream,
            int $want,
        ) use (
            &$sent,
            $length,
            $abortAfter,
            $token,
        ): string {
            // Stop feeding the declared length: the client times out below and closes mid-body.
            if ($sent >= $abortAfter) {
                return '';
            }

            $chunk = \max(0, \min($want, $length - $sent));
            $offset = $sent % \strlen($token);
            $sent += $chunk;

            // The probe's own token, repeated: a spool of this request is recognisable by its first bytes.
            return \substr(\str_repeat($token, \intdiv($offset + $chunk, \strlen($token)) + 1), $offset, $chunk);
        };

        \curl_setopt_array($handle, [
            CURLOPT_UPLOAD => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_INFILESIZE => $length,
            CURLOPT_TIMEOUT => 'aborted' === $ending ? 3 : 120,
            CURLOPT_MAX_SEND_SPEED_LARGE => 'refused slowly' === $ending ? self::SLOW_BYTES_PER_SECOND : 0,
            CURLOPT_USERAGENT => $anchor,
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Expect:'],
        ]);
        \curl_setopt($handle, CURLOPT_READFUNCTION, $feed);

        // An abort and a server closing under an oversized body are both expected outcomes here.
        $body = \curl_exec($handle);
        $status = \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if (201 === $status && \is_string($body)) {
            $decoded = \json_decode($body, true);

            if (\is_array($decoded)) {
                $this->documents[] = Dig::string($decoded, 'data', 'id');
            }
        }

        return $status;
    }

    private function handle(string $url): CurlHandle
    {
        $handle = \curl_init($url);

        if (!$handle instanceof CurlHandle || null === self::$cookieJar) {
            throw new RuntimeException('Could not initialise the probe.');
        }

        \curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_COOKIEFILE => self::$cookieJar,
            CURLOPT_COOKIEJAR => self::$cookieJar,
        ]);

        return $handle;
    }

    private function bytesReadBy(string $written, string $anchor): int
    {
        foreach (\explode("\n", $written) as $line) {
            if (\str_contains($line, $anchor) && 1 === \preg_match('/"bytes_read":(\d+)/', $line, $matches)) {
                return (int) $matches[1];
            }
        }

        $this->fail("The probe's access-log line records no bytes_read.");
    }

    /**
     * The request has ended once its access-log line exists, so no spool of it may remain in `upload_tmp_dir`: the
     * application destroys its plaintext copy of the body as soon as it stops reading it, whatever the outcome.
     */
    private function assertNoSpoolHolds(string $token): void
    {
        $directory = (string) \ini_get('upload_tmp_dir');
        $this->assertDirectoryExists($directory);

        foreach (\glob($directory . '/php*') ?: [] as $spool) {
            $this->assertNotSame(
                $token,
                \file_get_contents($spool, false, null, 0, \strlen($token)),
                "the request's plaintext body is still spooled",
            );
        }
    }
}
