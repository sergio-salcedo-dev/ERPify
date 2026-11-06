<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use CurlHandle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The evidence PUT carries the caller's original filename, percent-encoded, in its query — so the access log
 * and the container log are the first sinks it reaches, before the application has validated anything. This
 * sends REAL PUTs through the running Caddy (the sibling `AccessLogQueryContainmentGateTest` is GET-only) in
 * the three shapes a body can end: completed, aborted mid-body, and past the route's 100 MiB bound. Each carries
 * a distinctive name and the line Caddy wrote is searched for it, encoded and decoded.
 *
 * They go unauthenticated on purpose: Caddy writes its entry whatever the application answers, and the body is
 * sent whole whether or not anything reads it. What the application does with an authenticated PUT is covered
 * by the kernel-level functional test and the Monolog/Sentry unit rows.
 *
 * Two sinks are read: Caddy's access log, and the container's own log. The latter lives on the host (Docker's
 * json-file driver), so the dev entrypoint tees everything PID 1 writes into `CONTAINER_LOG_MIRROR`
 * (compose.dev.yaml) and this reads that copy; it must show the probe arriving (the router records the
 * route path on stderr in dev) before its silence about the name means anything.
 *
 * @internal
 */
#[CoversNothing]
final class UploadFilenameAccessLogContainmentGateTest extends TestCase
{
    private const string PATH = '/api/v1/documents/uploads/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c/content';

    private const int BOUND = 104_857_600;

    private const int FLUSH_TIMEOUT_MICROSECONDS = 10_000_000;

    private const int FLUSH_POLL_MICROSECONDS = 100_000;

    #[Test]
    #[DataProvider('provideTheNameNeverReachesTheAccessLogCases')]
    public function theNameNeverReachesTheAccessLog(string $ending): void
    {
        $name = 'sentinel-' . \bin2hex(\random_bytes(8)) . ' Acta é.pdf';
        $anchor = 'anchor' . \bin2hex(\random_bytes(10));
        $position = $this->accessLogPosition();
        $mirrorOffset = $this->mirrorSize();

        $this->put(self::PATH . '?filename=' . \rawurlencode($name), $anchor, $ending);

        $written = $this->accessLogWrittenSince($position, $anchor);
        $mirrored = $this->mirrorWrittenSince($mirrorOffset);

        $this->assertStringContainsString('documents_upload_content', $mirrored, 'the probe reached the container log');
        $this->assertStringNotContainsString(
            \rawurlencode($name),
            $mirrored,
            'the container log holds the encoded name',
        );
        $this->assertStringNotContainsString(\explode(' ', $name)[0], $mirrored, 'the container log holds the name');

        $this->assertStringContainsString('/api/v1/documents/uploads/', $written, 'the probe line arrived');
        $this->assertStringNotContainsString(\rawurlencode($name), $written);
        $this->assertStringNotContainsString(\urlencode($name), $written);
        $this->assertStringNotContainsString(\explode(' ', $name)[0], $written, 'not even the distinctive prefix');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheNameNeverReachesTheAccessLogCases(): iterable
    {
        yield 'completed' => ['completed'];
        yield 'aborted mid-body' => ['aborted'];
        yield 'past the bound' => ['oversized'];
    }

    /**
     * @param non-empty-string $anchor
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the read callback's signature is curl's
     */
    private function put(string $uri, string $anchor, string $ending): void
    {
        $handle = \curl_init('https://localhost' . $uri);

        if (!$handle instanceof CurlHandle) {
            throw new RuntimeException('Could not initialise the probe.');
        }

        $length = match ($ending) {
            'completed' => 1024,
            'aborted' => 1_000_000,
            default => self::BOUND + 1,
        };
        $abortAfter = 'aborted' === $ending ? 65_536 : PHP_INT_MAX;
        // `$curl` and `$stream` are the callback's fixed signature.
        $sent = 0;

        $feed = static function (
            CurlHandle $curl,
            mixed $stream,
            int $want,
        ) use (
            &$sent,
            $length,
            $abortAfter,
        ): string {
            // Stop feeding the declared length: the client times out below and closes mid-body.
            if ($sent >= $abortAfter) {
                return '';
            }

            $chunk = \min($want, $length - $sent);
            $sent += $chunk;

            return \str_repeat('a', \max(0, $chunk));
        };

        \curl_setopt_array($handle, [
            CURLOPT_UPLOAD => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_INFILESIZE => $length,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 'aborted' === $ending ? 3 : 60,
            CURLOPT_USERAGENT => $anchor,
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Expect:'],
        ]);

        \curl_setopt($handle, CURLOPT_READFUNCTION, $feed);

        // An abort and a server closing under an oversized body are both expected outcomes here.
        \curl_exec($handle);
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
