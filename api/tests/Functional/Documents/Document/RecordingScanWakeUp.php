<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Application\Upload\DocumentScanWakeUp;
use Override;

/**
 * Records every scan wake-up instead of dispatching it, so a recovery test can count them, and whether a
 * transaction was open on the connection when each one was published.
 */
final class RecordingScanWakeUp implements DocumentScanWakeUp
{
    /** @var list<string> */
    public array $woken = [];

    /** @var list<bool> */
    public array $insideTransaction = [];

    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    #[Override]
    public function wake(string $documentId): void
    {
        $this->woken[] = $documentId;
        $this->insideTransaction[] = $this->connection?->isTransactionActive() ?? false;
    }
}
