<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Scanning\SweepCappedDocuments;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One cap-sweep pass; idempotent, each row re-evaluated by its own compare-and-set.
 */
#[AsMessageHandler]
final readonly class SweepCappedDocumentsHandler
{
    public function __construct(
        private SweepCappedDocuments $sweepCappedDocuments,
    ) {
    }

    public function __invoke(SweepCappedDocumentsMessage $message): void
    {
        unset($message);

        $this->sweepCappedDocuments->run();
    }
}
