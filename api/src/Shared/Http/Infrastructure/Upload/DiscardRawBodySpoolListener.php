<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure\Upload;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use const PHP_SAPI;

/**
 * Destroys the server's plaintext copy of a raw-body upload's body before its response leaves, whatever that
 * response is: stored, refused by the processor, or refused before anything read the body (an anonymous 401, a 403,
 * a 404, a refused length).
 *
 * The body is read to its end here rather than left to the server: a request ending with its body unread has the rest
 * consumed after the application returns and cached into the same spool, where nothing in the application can reach
 * it any more. Reading it re-reads the cached part from the start and takes the rest, so the hash and count identify
 * the spool exactly; {@see PhpUploadBodySpool} then destroys it. Last of the response listeners, so nothing after it
 * reads the body again.
 *
 * The rest of the body arrives at the client's pace, so the drain stops at its own deadline, and the time limit is
 * armed to outlast it by {@see LIMIT_MARGIN_SECONDS} and re-armed with what is left at every chunk: a refused body read
 * under whatever limit was armed before would end in a fatal error, turning the answer into a 500 and keeping the
 * spool. Past the deadline it stops reading, and a spool read only in part is matched on the prefix that was read.
 */
final readonly class DiscardRawBodySpoolListener
{
    private const int READ_CHUNK_BYTES = 1_048_576;

    /** Seconds the time limit is armed past the drain's own deadline, so the drain stops before PHP does. */
    private const int LIMIT_MARGIN_SECONDS = 5;

    /** The time limit once the body is drained: room to match a spool and for the listeners that run after. */
    private const int AFTER_DRAIN_SECONDS = 60;

    /** Longest the drain waits for the rest of a body that is no longer wanted. */
    private const int DRAIN_DEADLINE_SECONDS = 120;

    /**
     * @param string $input where the request body is read from; the SAPI's own stream in production, a file in a test
     */
    public function __construct(private PhpUploadBodySpool $spool, private string $input = 'php://input')
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -4096)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || true !== $event->getRequest()->attributes->get(RawBodyRoute::ATTRIBUTE)) {
            return;
        }

        $body = \fopen($this->input, 'r');

        if (false === $body) {
            return;
        }

        $hash = \hash_init('sha256');
        $head = '';
        $bytes = 0;
        $deadline = \hrtime(true) / 1e9 + self::DRAIN_DEADLINE_SECONDS;

        try {
            while (!\feof($body)) {
                $left = (int) \ceil($deadline - \hrtime(true) / 1e9);

                if ($left <= 0) {
                    break;
                }

                $this->armTimeLimit($left + self::LIMIT_MARGIN_SECONDS);
                $chunk = \fread($body, self::READ_CHUNK_BYTES);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                \hash_update($hash, $chunk);
                $bytes += \strlen($chunk);

                if (\strlen($head) < PhpUploadBodySpool::HEAD_BYTES) {
                    $head .= \substr($chunk, 0, PhpUploadBodySpool::HEAD_BYTES - \strlen($head));
                }
            }
        } finally {
            \fclose($body);
        }

        $this->armTimeLimit(self::AFTER_DRAIN_SECONDS);
        $this->spool->discard($bytes, \hash_final($hash), \hash('sha256', $head));
    }

    /**
     * Wall-clock under FrankenPHP's threads, blocked reads included, and inert on the CLI where a limit armed by a
     * test or a console command would outlive it and cut the process short.
     */
    private function armTimeLimit(int $seconds): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        \set_time_limit($seconds);
    }
}
