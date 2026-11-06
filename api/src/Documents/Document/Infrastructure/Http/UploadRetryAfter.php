<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Domain\Exception\UploadInProgress;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders `Retry-After` on the conflict an upload in progress answers. The seconds travel on the request rather
 * than in the problem body's extensions, which a client would have to parse to learn what a header already says.
 */
final class UploadRetryAfter
{
    private const string ATTRIBUTE = '_documents_upload_retry_after';

    public static function stampOn(Request $request, UploadInProgress $inProgress): void
    {
        $request->attributes->set(self::ATTRIBUTE, $inProgress);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $inProgress = $event->getRequest()->attributes->get(self::ATTRIBUTE);

        $refused = $event->getResponse()->getStatusCode() >= Response::HTTP_BAD_REQUEST;

        if ($inProgress instanceof UploadInProgress && $refused) {
            $event->getResponse()->headers->set('Retry-After', (string) $inProgress->retryAfterSeconds);
        }
    }
}
