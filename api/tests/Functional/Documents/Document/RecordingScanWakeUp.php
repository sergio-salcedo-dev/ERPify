<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Upload\DocumentScanWakeUp;
use Override;

/**
 * Records every scan wake-up instead of dispatching it, so a recovery test can count them.
 */
final class RecordingScanWakeUp implements DocumentScanWakeUp
{
    /** @var list<string> */
    public array $woken = [];

    #[Override]
    public function wake(string $documentId): void
    {
        $this->woken[] = $documentId;
    }
}
