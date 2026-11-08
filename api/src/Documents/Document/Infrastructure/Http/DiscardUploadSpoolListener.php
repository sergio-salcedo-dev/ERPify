<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Infrastructure\Upload\PhpUploadBodySpool;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Destroys the server's plaintext copy of an evidence PUT's body before its response leaves, whatever that response
 * is: filed, aborted, or refused before anything read the body (an anonymous 401, a 403, a 410, a refused length).
 *
 * The body is read to its end here rather than left to the server: a request ending with its body unread has the
 * rest consumed after the application returns and cached into the same spool (measured — a refused PUT's response
 * waits for the whole body and leaves a spool of it), where nothing in the application can reach it any more.
 * Reading it here re-reads the cached part from the start and takes the rest, so the hash and count identify the
 * spool exactly; {@see PhpUploadBodySpool} then destroys it. Last of the response listeners, so nothing after it
 * reads the body again.
 */
final readonly class DiscardUploadSpoolListener
{
    private const int READ_CHUNK_BYTES = 1_048_576;

    public function __construct(
        private PhpUploadBodySpool $spool,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -4096)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || UploadRoutes::CONTENT !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $body = \fopen('php://input', 'r');

        if (false === $body) {
            return;
        }

        $hash = \hash_init('sha256');
        $bytes = 0;

        try {
            while (!\feof($body)) {
                $chunk = \fread($body, self::READ_CHUNK_BYTES);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                \hash_update($hash, $chunk);
                $bytes += \strlen($chunk);
            }
        } finally {
            \fclose($body);
        }

        $this->spool->discard($bytes, \hash_final($hash));
    }
}
