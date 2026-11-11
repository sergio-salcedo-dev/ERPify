<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumes {@see ScanDocumentMessage} and does nothing, deliberately, until the scanner exists: without a
 * handler every wake-up would fail and land on the `failed` queue. It never touches the row — the row is the
 * obligation the scan sweep will read — so a wake-up delivered here is not lost, only early.
 */
#[AsMessageHandler]
final readonly class ScanDocumentHandler
{
    public function __invoke(ScanDocumentMessage $message): void
    {
        unset($message);
    }
}
