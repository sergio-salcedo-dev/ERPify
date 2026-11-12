<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger;

use Erpify\Documents\Document\Application\Scanning\ScanDueDocuments;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumes {@see ScanDocumentMessage} by running one pass of the scan sweep. The message names a document but the
 * pass does not read it: the row is the obligation and the sweep is the only driver, so a wake-up that is lost,
 * duplicated or late changes nothing, and one delivered here only starts a pass early. A fault inside one
 * document's scan is logged by the sweep and passed over; a fault outside it (the probe, the candidate query, a
 * database outage) propagates, and Messenger's retry then only re-runs a pass that is idempotent — the retry is not
 * what drives a scan, the row stays the obligation.
 */
#[AsMessageHandler]
final readonly class ScanDocumentHandler
{
    public function __construct(
        private ScanDueDocuments $scanDueDocuments,
    ) {
    }

    public function __invoke(ScanDocumentMessage $message): void
    {
        unset($message);

        $this->scanDueDocuments->run();
    }
}
