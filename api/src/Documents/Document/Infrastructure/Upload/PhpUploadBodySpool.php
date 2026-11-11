<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Upload;

use HashContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * The server's own copy of an evidence PUT's body. PHP caches every byte read from a request body in a temporary
 * stream, which spills to a named `php*` file in `upload_tmp_dir` from {@see SPOOL_THRESHOLD_BYTES} on and is held
 * open by the worker thread until it serves its next request — so the plaintext original would outlive its upload,
 * filed, aborted or refused, by however long that thread waits. This destroys it: truncated first, which returns its
 * tmpfs pages at once although the worker thread still holds the file open, then unlinked.
 *
 * PHP gives userland no handle on that file, so it is identified by what only this request can know: a `php*` file
 * whose size is at least the bytes read and at most one read-ahead past them, and whose leading bytes hash to exactly
 * what was read — the first {@see HEAD_BYTES} compared first, so a same-size candidate that differs is never hashed
 * whole. A body under {@see SPOOL_THRESHOLD_BYTES} never leaves memory, so nothing is looked for. From it on this
 * request's own file necessarily exists and matches; one match is therefore that file, and two or more — another
 * in-flight upload carrying the same bytes — leave every candidate alone, since destroying another request's spool
 * would cut its body short. That and a match not found are logged.
 */
final readonly class PhpUploadBodySpool
{
    /** PHP's `SAPI_POST_BLOCK_SIZE`: a request body of this many bytes or more spills to a file (measured). */
    public const int SPOOL_THRESHOLD_BYTES = 16_384;

    /** The leading bytes whose hash is compared before a candidate is hashed whole. */
    public const int HEAD_BYTES = 65_536;

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
     * @param int    $bytesRead  the bytes this request read from its body, in order
     * @param string $sha256     the lower-case hex sha256 of exactly those bytes
     * @param string $headSha256 the lower-case hex sha256 of the first {@see HEAD_BYTES} of them (all, when fewer)
     */
    public function discard(int $bytesRead, string $sha256, string $headSha256): void
    {
        if ($bytesRead < self::SPOOL_THRESHOLD_BYTES || '' === $this->directory) {
            return;
        }

        try {
            $matches = \array_values(\array_filter(
                \glob($this->directory . '/php*') ?: [],
                fn (string $path): bool => $this->holds($path, $bytesRead, $sha256, $headSha256),
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
    private function holds(string $path, int $bytesRead, string $sha256, string $headSha256): bool
    {
        try {
            $hash = $this->hashOfPrefix($path, $bytesRead, $headSha256);

            return null !== $hash && \hash_equals($sha256, $hash);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The hash of the candidate's first `$bytesRead` bytes, or null as soon as it cannot be this request's: a size
     * outside the window, or a head that differs.
     */
    private function hashOfPrefix(string $path, int $bytesRead, string $headSha256): ?string
    {
        $handle = $this->sizeWithinWindow($path, $bytesRead) ? \fopen($path, 'r') : false;

        if (false === $handle) {
            return null;
        }

        try {
            $head = $this->headMatching($handle, $bytesRead, $headSha256);

            if (null === $head) {
                return null;
            }

            $context = \hash_init('sha256');
            \hash_update($context, $head);

            return $this->finishHash($handle, $context, $bytesRead - \strlen($head));
        } finally {
            \fclose($handle);
        }
    }

    /**
     * The candidate's leading bytes when they hash to what the request read first, else null.
     *
     * @param resource $handle
     */
    private function headMatching(mixed $handle, int $bytesRead, string $headSha256): ?string
    {
        $head = \fread($handle, \max(1, \min($bytesRead, self::HEAD_BYTES)));

        return false !== $head && \hash_equals($headSha256, \hash('sha256', $head)) ? $head : null;
    }

    private function sizeWithinWindow(string $path, int $bytesRead): bool
    {
        \clearstatcache(true, $path);
        $size = \is_file($path) ? \filesize($path) : false;

        return false !== $size && $size >= $bytesRead && $size <= $bytesRead + self::READ_AHEAD_BYTES;
    }

    /**
     * @param resource $handle positioned just past the head
     * @param int      $left   bytes still to hash
     */
    private function finishHash(mixed $handle, HashContext $context, int $left): ?string
    {
        while ($left > 0) {
            $chunk = \fread($handle, \min($left, self::HASH_CHUNK_BYTES));

            if (false === $chunk || '' === $chunk) {
                return null;
            }

            \hash_update($context, $chunk);
            $left -= \strlen($chunk);
        }

        return \hash_final($context);
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
