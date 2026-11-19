<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Erasure\ReleaseErasedDerivatives;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One derivative release tick. Idempotent: a release re-queries by origin, a duplicate deletion request is absorbed by
 * the image deletion, and the marker is a compare-and-set.
 */
#[AsMessageHandler]
final readonly class ReleaseErasedDerivativesHandler
{
    private const int BATCH = 200;

    public function __construct(
        private ReleaseErasedDerivatives $releaseErasedDerivatives,
    ) {
    }

    public function __invoke(ReleaseErasedDerivativesMessage $message): void
    {
        unset($message);

        $this->releaseErasedDerivatives->run(self::BATCH);
    }
}
