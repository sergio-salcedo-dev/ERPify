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

    private const int FLUSH_TIMEOUT_MICROSECONDS = 10_000_000;

    private const int FLUSH_POLL_MICROSECONDS = 100_000;

    /** @var non-empty-string|null */
    private static ?string $cookieJar = null;

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
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$cookieJar && \is_file(self::$cookieJar)) {
            \unlink(self::$cookieJar);
        }
    }

    #[Override]
    protected function setUp(): void
    {
        $this->rows = new DevStackUploadRows();
        [$status] = $this->request('POST', '/api/v1/backoffice/login', \json_encode([
            'email' => DevStackUploadRows::EMAIL,
            'password' => DevStackUploadRows::PASSWORD,
        ]) ?: '');
        $this->assertSame(204, $status, 'the e2e administrator could not sign in through Caddy');
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
        $upload = $this->declare(match ($ending) {
            'completed' => self::OVER_ONE_MEGABYTE,
            'aborted' => self::ABORTED_SIZE,
            default => UploadLimits::MAX_ORIGINAL_BYTES,
        });
        $position = $this->accessLogPosition();
        $mirrorOffset = $this->mirrorSize();

        $token = \random_bytes(16);
        $status = $this->put($upload, $name, $anchor, $ending, $token);

        $written = $this->accessLogWrittenSince($position, $anchor);
        $mirrored = $this->mirrorWrittenSince($mirrorOffset);
        $this->assertNoSpoolHolds($token);

        if ('completed' === $ending) {
            $this->assertSame(201, $status, 'a body over 1 MB on the canonical path is filed');
        }

        if ('aborted' === $ending) {
            $this->assertSame(
                'expired',
                $this->rows->stateOf($upload),
                'the application aborted the upload whose body ended short',
            );
        }

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
            'completed' => self::OVER_ONE_MEGABYTE,
            'aborted' => self::ABORTED_SIZE,
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

    private function mirrorPath(): string
    {
        $path = \getenv('CONTAINER_LOG_MIRROR');

        if (!\is_string($path) || '' === $path || !\is_readable($path)) {
            throw new RuntimeException(
                'The container-log mirror (CONTAINER_LOG_MIRROR, compose.dev.yaml) is not readable, so the '
                    . "container's own log cannot be observed; restore it rather than relaxing this into a skip.",
            );
        }

        return $path;
    }

    private function mirrorSize(): int
    {
        \clearstatcache(true, $this->mirrorPath());

        return (int) \filesize($this->mirrorPath());
    }

    /**
     * Polls until the mirror shows the probe's route (the dev router logs the matched route on stderr), then
     * returns what was written since the offset; a probe that never reaches it fails instead of reading empty.
     */
    private function mirrorWrittenSince(int $offset): string
    {
        for ($waited = 0; $waited < self::FLUSH_TIMEOUT_MICROSECONDS; $waited += self::FLUSH_POLL_MICROSECONDS) {
            \clearstatcache(true, $this->mirrorPath());
            $size = (int) \filesize($this->mirrorPath());
            $written = (string) \file_get_contents($this->mirrorPath(), false, null, $size < $offset ? 0 : $offset);

            if (\str_contains($written, 'documents_upload_content')) {
                return $written;
            }

            \usleep(self::FLUSH_POLL_MICROSECONDS);
        }

        throw new RuntimeException('The probe never reached the container-log mirror.');
    }

    private function accessLogPath(): string
    {
        $path = '/var/log/caddy/access.log';

        if (!\is_readable($path)) {
            throw new RuntimeException(\sprintf(
                'The access log is not readable at %s, so this gate can observe nothing; run it inside the php '
                    . 'container rather than relaxing it into a skip.',
                $path,
            ));
        }

        return $path;
    }

    /**
     * @return array{inode: int, offset: int}
     */
    private function accessLogPosition(): array
    {
        \clearstatcache(true, $this->accessLogPath());
        $path = $this->accessLogPath();

        return ['inode' => (int) \fileinode($path), 'offset' => (int) \filesize($path)];
    }

    /**
     * Polls until the probe's own entry (anchored by its user agent) arrives, and fails rather than returning an
     * empty read, over which every assertion above would pass.
     *
     * @param array{inode: int, offset: int} $position
     */
    private function accessLogWrittenSince(array $position, string $anchor): string
    {
        for ($waited = 0; $waited < self::FLUSH_TIMEOUT_MICROSECONDS; $waited += self::FLUSH_POLL_MICROSECONDS) {
            \clearstatcache(true, $this->accessLogPath());
            $handle = \fopen($this->accessLogPath(), 'r');

            if (false === $handle) {
                throw new RuntimeException('Could not open the access log.');
            }

            $stat = \fstat($handle);
            $rolled = false === $stat || $stat['ino'] !== $position['inode'] || $stat['size'] < $position['offset'];
            \fseek($handle, $rolled ? 0 : $position['offset']);
            $written = (string) \stream_get_contents($handle);
            \fclose($handle);

            if (\str_contains($written, $anchor)) {
                return $written;
            }

            \usleep(self::FLUSH_POLL_MICROSECONDS);
        }

        throw new RuntimeException(\sprintf('No access-log entry anchored on "%s" arrived.', $anchor));
    }
}
