<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Scanning\ScanDueDocuments;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One scan pass; idempotent, each claim a compare-and-set on the row.
 */
#[AsMessageHandler]
final readonly class ScanDueDocumentsHandler
{
    public function __construct(
        private ScanDueDocuments $scanDueDocuments,
    ) {
    }

    public function __invoke(ScanDueDocumentsMessage $message): void
    {
        unset($message);

        $this->scanDueDocuments->run();
    }
}
