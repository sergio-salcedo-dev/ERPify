<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Clamd;

use RuntimeException;

/**
 * A child process speaking enough of clamd's protocol for
 * {@see \Erpify\Documents\Document\Infrastructure\Scanning\ClamdScanner} to be exercised over a real socket.
 * The mode picks the reply to every stream: `ok`, `found`, `limit-found`, `size`, `error`, `slow` (answers after
 * three seconds), `drop` (closes with no reply) or `size-close` (answers the size error and closes after the first
 * frame header, the way clamd treats a stream past its ceiling) or `close-early` (closes there without saying why).
 */
final class FakeClamd
{
    /** @var resource */
    private $process;

    private readonly int $port;

    public function __construct(string $mode)
    {
        $pipes = [];
        $process = \proc_open(
            [PHP_BINARY, __DIR__ . '/fake-clamd.php', $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($process)) {
            throw new RuntimeException('The fake clamd could not be started.');
        }

        $this->process = $process;
        /** @var array{1: resource, 2: resource} $pipes */
        $port = \fgets($pipes[1]);

        if (false === $port || !\ctype_digit(\trim($port))) {
            throw new RuntimeException('The fake clamd did not report a port.');
        }

        $this->port = (int) \trim($port);
    }

    public function address(): string
    {
        return 'tcp://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        \proc_terminate($this->process);
        \proc_close($this->process);
    }
}
