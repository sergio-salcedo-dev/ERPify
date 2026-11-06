<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

/**
 * Wakes the scan of a promoted document. A hint, never the obligation: the obligation is the row itself
 * (`promoted_at` set, `next_scan_at` due), which the scan sweep reads, so a wake-up that is lost, duplicated or
 * delivered before anything consumes it loses nothing.
 */
interface DocumentScanWakeUp
{
    public function wake(string $documentId): void;
}
