<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Scanning\ExpireScanLeases;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One lease-expiry pass; idempotent, each return a compare-and-set on the stale epoch.
 */
#[AsMessageHandler]
final readonly class ExpireScanLeasesHandler
{
    public function __construct(
        private ExpireScanLeases $expireScanLeases,
    ) {
    }

    public function __invoke(ExpireScanLeasesMessage $message): void
    {
        unset($message);

        $this->expireScanLeases->run();
    }
}
