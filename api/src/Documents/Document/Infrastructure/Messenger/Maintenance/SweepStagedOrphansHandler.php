<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Upload\SweepStagedOrphans;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One staging age-sweep tick; idempotent, since a deletion of an absent object is already satisfied.
 */
#[AsMessageHandler]
final readonly class SweepStagedOrphansHandler
{
    private const int BATCH = 1000;

    public function __construct(
        private SweepStagedOrphans $sweepStagedOrphans,
    ) {
    }

    public function __invoke(SweepStagedOrphansMessage $message): void
    {
        unset($message);

        $this->sweepStagedOrphans->sweep(self::BATCH);
    }
}
