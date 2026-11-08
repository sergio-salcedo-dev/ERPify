<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Upload;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * The server's own copy of an evidence PUT's body. PHP caches every byte read from a request body in a temporary
 * stream, which spills to a named `php*` file in `upload_tmp_dir` past {@see SPOOL_THRESHOLD_BYTES} and is held
 * open by the worker thread until it serves its next request — so the plaintext original would outlive its upload,
 * filed, aborted or refused, by however long that thread waits. This destroys it: truncated first, which returns its
 * tmpfs pages at once although the worker thread still holds the file open, then unlinked.
 *
 * PHP gives userland no handle on that file, so it is identified by what only this request can know: a `php*` file
 * whose size is at least the bytes read and at most one read-ahead past them, and whose leading bytes hash to exactly
 * what was read. A body of at most {@see SPOOL_THRESHOLD_BYTES} never leaves memory, so nothing is looked for. Above
 * it this request's own file necessarily exists and matches; one match is therefore that file, and two or more —
 * another in-flight upload carrying the same bytes — leave every candidate alone, since destroying another request's
 * spool would cut its body short. That and a match not found are logged and the file waits for its thread, as every
 * spool did before.
 */
final readonly class PhpUploadBodySpool
{
    /** PHP's `SAPI_POST_BLOCK_SIZE`: the request body cache stays in memory up to this many bytes (measured). */
    public const int SPOOL_THRESHOLD_BYTES = 16_384;

    /** How far past the bytes read PHP's stream layer may have read ahead into the cache. */
    private const int READ_AHEAD_BYTES = 131_072;

    private const int HASH_CHUNK_BYTES = 1_048_576;

    private string $directory;

    public function __construct(
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
        ?string $directory = null,
    ) {
        $this->directory = $directory ?? (string) \ini_get('upload_tmp_dir');
    }

    /**
     * Best effort and silent: a spool it cannot identify with certainty is left alone rather than guessed at.
     *
     * @param int    $bytesRead the bytes this request read from its body, in order
     * @param string $sha256    the lower-case hex sha256 of exactly those bytes
     */
    public function discard(int $bytesRead, string $sha256): void
    {
        if ($bytesRead <= self::SPOOL_THRESHOLD_BYTES || '' === $this->directory) {
            return;
        }

        try {
            $matches = \array_values(\array_filter(
                \glob($this->directory . '/php*') ?: [],
                fn (string $path): bool => $this->holds($path, $bytesRead, $sha256),
            ));

            if (1 !== \count($matches)) {
                $this->logger->warning('An upload spool was left for its worker thread.', [
                    'candidates' => \count($matches),
                ]);

                return;
            }

            $this->destroy($matches[0]);
        } catch (Throwable $throwable) {
            $this->logger->warning('An upload spool could not be destroyed.', [
                'exceptionClass' => $throwable::class,
            ]);
        }
    }

    /**
     * Another thread's spool can vanish while it is being looked at — its request ended — so any failure to read a
     * candidate means it is not this request's.
     */
    private function holds(string $path, int $bytesRead, string $sha256): bool
    {
        try {
            $hash = $this->hashOfPrefix($path, $bytesRead);

            return null !== $hash && \hash_equals($sha256, $hash);
        } catch (Throwable) {
            return false;
        }
    }

    private function hashOfPrefix(string $path, int $bytesRead): ?string
    {
        \clearstatcache(true, $path);
        $size = \is_file($path) ? \filesize($path) : false;

        if (false === $size || $size < $bytesRead || $size > $bytesRead + self::READ_AHEAD_BYTES) {
            return null;
        }

        $handle = \fopen($path, 'r');

        if (false === $handle) {
            return null;
        }

        try {
            $context = \hash_init('sha256');
            $left = $bytesRead;

            while ($left > 0) {
                $chunk = \fread($handle, \min($left, self::HASH_CHUNK_BYTES));

                if (false === $chunk || '' === $chunk) {
                    return null;
                }

                \hash_update($context, $chunk);
                $left -= \strlen($chunk);
            }

            return \hash_final($context);
        } finally {
            \fclose($handle);
        }
    }

    private function destroy(string $path): void
    {
        $handle = \fopen($path, 'r+');

        if (false !== $handle) {
            \ftruncate($handle, 0);
            \fclose($handle);
        }

        \unlink($path);
    }
}
