<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use RuntimeException;

/**
 * The two sinks a through-Caddy probe reads in the dev stack: Caddy's access log, and the container's own log
 * through the mirror the dev entrypoint tees PID 1's output into (`CONTAINER_LOG_MIRROR`, compose.dev.yaml).
 * Both reads fail rather than return empty, over which every "the sink holds nothing" assertion would pass.
 *
 * @internal
 */
final class DevStackLogs
{
    private const int FLUSH_TIMEOUT_MICROSECONDS = 10_000_000;

    private const int FLUSH_POLL_MICROSECONDS = 100_000;

    private static function mirrorPath(): string
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

    public static function mirrorSize(): int
    {
        \clearstatcache(true, self::mirrorPath());

        return (int) \filesize(self::mirrorPath());
    }

    /**
     * Polls until the mirror shows the probe's route (the dev router logs the matched route on stderr), then
     * returns what was written since the offset; a probe that never reaches it fails instead of reading empty.
     */
    public static function mirrorWrittenSince(int $offset): string
    {
        for ($waited = 0; $waited < self::FLUSH_TIMEOUT_MICROSECONDS; $waited += self::FLUSH_POLL_MICROSECONDS) {
            \clearstatcache(true, self::mirrorPath());
            $size = (int) \filesize(self::mirrorPath());
            $written = (string) \file_get_contents(self::mirrorPath(), false, null, $size < $offset ? 0 : $offset);

            if (\str_contains($written, 'documents_upload_content')) {
                return $written;
            }

            \usleep(self::FLUSH_POLL_MICROSECONDS);
        }

        throw new RuntimeException('The probe never reached the container-log mirror.');
    }

    private static function accessLogPath(): string
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
    public static function accessLogPosition(): array
    {
        \clearstatcache(true, self::accessLogPath());
        $path = self::accessLogPath();

        return ['inode' => (int) \fileinode($path), 'offset' => (int) \filesize($path)];
    }

    /**
     * Polls until the probe's own entry (anchored by its user agent) arrives, and fails rather than returning an
     * empty read, over which every assertion above would pass.
     *
     * @param array{inode: int, offset: int} $position
     */
    public static function accessLogWrittenSince(array $position, string $anchor): string
    {
        for ($waited = 0; $waited < self::FLUSH_TIMEOUT_MICROSECONDS; $waited += self::FLUSH_POLL_MICROSECONDS) {
            \clearstatcache(true, self::accessLogPath());
            $handle = \fopen(self::accessLogPath(), 'r');

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
