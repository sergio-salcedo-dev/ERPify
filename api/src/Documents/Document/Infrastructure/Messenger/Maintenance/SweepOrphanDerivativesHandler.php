<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Erasure\SweepOrphanDerivatives;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One orphan derivative tick. Idempotent: a release re-queries by origin, and a duplicate deletion request is absorbed
 * by the image deletion.
 */
#[AsMessageHandler]
final readonly class SweepOrphanDerivativesHandler
{
    private const int PAGE = 200;

    public function __construct(
        private SweepOrphanDerivatives $sweepOrphanDerivatives,
    ) {
    }

    public function __invoke(SweepOrphanDerivativesMessage $message): void
    {
        unset($message);

        $this->sweepOrphanDerivatives->run(self::PAGE);
    }
}
