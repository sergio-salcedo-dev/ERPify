<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger;

/**
 * Wakes the scan of one promoted document. A hint carrying a document id and nothing else — no actor, no
 * filename, no scope — routed to `async`. A document is not a person, so its id on a persisted transport outlives
 * no one's erasure; the obligation itself lives on the row (`promoted_at`, `next_scan_at`), so a lost or late
 * delivery loses nothing.
 */
final readonly class ScanDocumentMessage
{
    public function __construct(
        public string $documentId,
    ) {
    }
}
