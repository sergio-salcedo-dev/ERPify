<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Scanning;

/**
 * One TCP connection to clamd, with the socket calls whose failure is an answer rather than an event: a refused
 * connect, a closed peer or a timed-out read each raise a PHP warning that the caller turns into a typed outcome, and
 * the warning would otherwise reach the log with the scanner's address in it. The handler that swallows it is scoped
 * to the one call and restored in `finally`, where the `@` operator is not.
 */
final class ClamdSocket
{
    private const int REPLY_LIMIT_BYTES = 4096;

    /**
     * @param resource $stream
     */
    private function __construct(private $stream)
    {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedLocalVariable") `stream_socket_client` fills the error pair by reference
     */
    public static function open(string $address, int $timeoutSeconds): ?self
    {
        \set_error_handler(static fn (): bool => true);

        try {
            $stream = \stream_socket_client($address, $errorCode, $errorMessage, $timeoutSeconds);
        } finally {
            \restore_error_handler();
        }

        return \is_resource($stream) ? new self($stream) : null;
    }

    public function setReadTimeout(int $seconds): void
    {
        \stream_set_timeout($this->stream, $seconds);
    }

    public function send(string $bytes): bool
    {
        \set_error_handler(static fn (): bool => true);

        try {
            $written = 0;
            $length = \strlen($bytes);

            while ($written < $length) {
                $count = \fwrite($this->stream, \substr($bytes, $written));

                if (false === $count || 0 === $count) {
                    return false;
                }

                $written += $count;
            }

            return true;
        } finally {
            \restore_error_handler();
        }
    }

    /** One `INSTREAM` chunk: a four-byte big-endian length, then the bytes. An empty chunk ends the stream. */
    public function sendChunk(string $bytes): bool
    {
        return $this->send(\pack('N', \strlen($bytes)) . $bytes);
    }

    /**
     * The first NUL-terminated reply, read as a poll: a timed-out read asks `$keepAlive` whether to go on, and the
     * wait ends after `$waitSeconds`. Null when there is none.
     *
     * @param callable(): bool $keepAlive
     */
    public function answer(callable $keepAlive, int $waitSeconds): ?string
    {
        $deadline = \hrtime(true) + $waitSeconds * 1_000_000_000;
        $reply = '';

        while (\strlen($reply) < self::REPLY_LIMIT_BYTES && \hrtime(true) < $deadline) {
            [$chunk, $ended] = $this->read();

            if ('' !== $chunk) {
                $reply .= $chunk;

                if (\str_contains($reply, "\0")) {
                    return \substr($reply, 0, (int) \strpos($reply, "\0"));
                }

                continue;
            }

            // Outside the silenced calls: the poll is the caller's heartbeat, and what it raises must be seen.
            if ($ended || !$keepAlive()) {
                return null;
            }
        }

        return null;
    }

    /**
     * One read, with warnings silenced for these two calls only.
     *
     * @return array{0: string, 1: bool} the bytes read (empty on a timeout) and whether the peer closed
     */
    private function read(): array
    {
        \set_error_handler(static fn (): bool => true);

        try {
            $chunk = \fread($this->stream, 1024);

            return [\is_string($chunk) ? $chunk : '', \feof($this->stream)];
        } finally {
            \restore_error_handler();
        }
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
    }
}
